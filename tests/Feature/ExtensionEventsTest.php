<?php namespace Tests\Feature;

use Hampel\EventLogging\XF\Repository\AdminLog;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * The events caught by a class extension, because XenForo writes them with a raw insert or does
 * not record them at all: the admin log, the spam trigger log, and email.
 */
class ExtensionEventsTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * The extension is registered against XenForo 2.2's class name, so this also proves a later
	 * XenForo still resolves it.
	 */
	public function test_the_admin_log_repository_is_extended()
	{
		$this->assertInstanceOf(AdminLog::class, $this->app()->repository('XF:AdminLog'));
	}

	public function test_an_admin_request_emits_the_route_and_never_the_submitted_form()
	{
		$this->enableGroup('admin');

		$logId = $this->app()->repository('XF:AdminLog')->logAdminRequest(
			3, '/admin.php?users/alice.12/save&_xfToken=tok-en', ['password' => 'correct-horse'], '203.0.113.9'
		);

		$records = $this->events->event('admin.action');
		$this->assertCount(1, $records);
		$this->assertSame([
			'event' => 'admin.action',
			'admin_log_id' => (int) $logId,
			'actor_user_id' => 3,
			'route' => 'users/alice.12/save',
		], $records[0]['context']);

		$this->assertStringNotContainsString('correct-horse', $this->emitted());
		$this->assertStringNotContainsString('tok-en', $this->emitted());
		$this->assertStringNotContainsString('203.0.113.9', $this->emitted());
	}

	/**
	 * Through XenForo's own admin controller, which is the only caller that matters: it passes
	 * the request's route path, not a URI. Every other test here calls the repository directly
	 * and so chooses that argument's shape for itself - which is how a route() that only
	 * understood URIs passed them all and logged no route on a live forum.
	 */
	public function test_an_admin_request_dispatched_by_xenforo_carries_its_route()
	{
		$this->enableGroup('admin');
		$this->actingAsMember(['user_id' => 1, 'is_admin' => true]);

		$this->dispatch('login/keep-alive', 'admin', [], [], 'POST');

		$records = $this->events->event('admin.action');
		$this->assertCount(1, $records);
		$this->assertSame('login/keep-alive', $records[0]['context']['route']);
		$this->assertSame(1, $records[0]['context']['actor_user_id']);
	}

	/**
	 * XenForo logs the keep-alive, so the mirror carries it unless the owner says otherwise.
	 */
	public function test_a_keep_alive_is_recorded_by_default()
	{
		$this->enableGroup('admin');
		$this->actingAsMember(['user_id' => 1, 'is_admin' => true]);

		$this->dispatch('login/keep-alive', 'admin', [], [], 'POST');

		$this->assertCount(1, $this->events->event('admin.action'));
	}

	public function test_a_keep_alive_is_left_out_when_the_option_is_on_and_still_reaches_the_admin_log()
	{
		$this->enableGroup('admin');
		$this->setOption('eventLoggingAdminSkipKeepAlive', true);
		$this->actingAsMember(['user_id' => 1, 'is_admin' => true]);
		$before = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_admin_log');

		$this->dispatch('login/keep-alive', 'admin', [], [], 'POST');

		$this->assertSame([], $this->events->records);
		$this->assertSame($before + 1, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_admin_log'), "XenForo's own log is untouched");
	}

	public function test_the_option_leaves_every_other_admin_request_alone()
	{
		$this->enableGroup('admin');
		$this->setOption('eventLoggingAdminSkipKeepAlive', true);
		$repo = $this->app()->repository('XF:AdminLog');

		$repo->logAdminRequest(3, 'options/update', [], '203.0.113.9');
		$repo->logAdminRequest(3, 'login/login', [], '203.0.113.9');
		$repo->logAdminRequest(3, 'login/keep-alive-settings', [], '203.0.113.9');

		$this->assertSame(
			['options/update', 'login/login', 'login/keep-alive-settings'],
			array_column(array_column($this->events->event('admin.action'), 'context'), 'route')
		);
	}

	public function test_an_admin_request_xenforo_does_not_log_emits_nothing()
	{
		$this->enableGroup('admin');

		$this->app()->repository('XF:AdminLog')->logAdminRequest(0, '/admin.php?users/1/save', [], '203.0.113.9');

		$this->assertSame([], $this->events->records);
	}

	public function test_the_admin_log_is_still_written_while_the_group_is_off()
	{
		$logId = $this->app()->repository('XF:AdminLog')->logAdminRequest(3, '/admin.php?users/1/save', [], '203.0.113.9');

		$this->assertSame([], $this->events->records);
		$this->assertDatabaseHas('xf_admin_log', ['admin_log_id' => $logId]);
	}

	public static function checkers(): array
	{
		return [['XF:UserChecker', 'user', 0], ['XF:ContentChecker', 'post', 42]];
	}

	#[DataProvider('checkers')]
	public function test_a_spam_trigger_emits_the_result_and_never_the_content(string $class, string $contentType, int $contentId)
	{
		$this->enableGroup('spam');
		$this->actingAsMember(['user_id' => 9]);
		$this->swap('request', new \XF\Http\Request(
			$this->app()->inputFilterer(), [], [], [], ['REMOTE_ADDR' => '203.0.113.9']
		));

		$checker = $this->app()->spam()->container()->create('checker', $class);
		$checker->logDecision('Test', 'allowed');
		$checker->logDecision('Test', 'moderated');
		$checker->logDetail('Test', 'spam_phrase_matched_x', ['phrase' => 'cheap watches']);

		$this->assertNotFalse($checker->logSpamTrigger($contentType, $contentId));

		$records = $this->events->event('spam.triggered');
		$this->assertCount(1, $records);
		$this->assertSame($contentType, $records[0]['context']['content_type']);
		$this->assertSame($contentId ?: null, $records[0]['context']['content_id']);
		$this->assertSame($contentType === 'user' ? 0 : 9, $records[0]['context']['user_id']);
		$this->assertSame('moderated', $records[0]['context']['result']);
		$this->assertSame('203.0.113.9', $records[0]['context']['ip']);
		$this->assertStringNotContainsString('cheap watches', $this->emitted());
	}

	public function test_a_spam_check_that_allowed_everything_emits_nothing()
	{
		$this->enableGroup('spam');

		$checker = $this->app()->spam()->container()->create('checker', 'XF:ContentChecker');
		$checker->logDecision('Test', 'allowed');

		$this->assertFalse($checker->logSpamTrigger('post', 42));
		$this->assertSame([], $this->events->records);
	}

	/**
	 * Who it went to and what it was called, to answer "did the forum send it?" - and never the
	 * body, which is where a reset link is.
	 */
	public function test_an_email_emits_mail_sent_with_its_recipients_and_subject_and_never_its_body()
	{
		$this->fakesMail();
		$this->enableGroup('mail');

		$this->app()->mailer()->newMail()
			->setTo('alice@example.com')
			->setContent('Your reset link', 'https://example.com/lost-password/secret-token')
			->send();

		$this->assertMailSentTimes(1);
		$records = $this->events->event('mail.sent');
		$this->assertCount(1, $records);
		$this->assertSame('info', $records[0]['level']);
		$this->assertSame([
			'event' => 'mail.sent',
			'recipient_count' => 1,
			'recipients' => ['alice@example.com'],
			'subject' => 'Your reset link',
		], $records[0]['context']);

		$this->assertStringNotContainsString('secret-token', $this->emitted());
	}

	public function test_an_email_the_transport_rejects_emits_mail_failed_as_a_warning()
	{
		$this->fakesMail();
		$this->fakesErrors();
		$this->enableGroup('mail');

		$failing = new class extends AbstractTransport
		{
			protected function doSend(SentMessage $message): void
			{
				throw new \RuntimeException('connection refused');
			}

			public function __toString(): string
			{
				return 'failing://';
			}
		};

		$sent = $this->app()->mailer()->newMail()
			->setTo('alice@example.com')
			->setContent('Subject', 'Body')
			->send($failing);

		$this->assertFalse((bool) $sent);
		$records = $this->events->event('mail.failed');
		$this->assertCount(1, $records);
		$this->assertSame('warning', $records[0]['level']);
		$this->assertSame(['alice@example.com'], $records[0]['context']['recipients']);
		$this->assertSame('Subject', $records[0]['context']['subject']);
		$this->assertSame([], $this->events->event('mail.sent'));
	}

	public function test_an_email_is_still_sent_while_the_group_is_off()
	{
		$this->fakesMail();

		$this->app()->mailer()->newMail()->setTo('alice@example.com')->setContent('Subject', 'Body')->send();

		$this->assertMailSentTimes(1);
		$this->assertSame([], $this->events->records);
	}

	public function test_an_api_request_emits_its_key_route_and_status()
	{
		$this->enableGroup('api');
		$this->actingAsMember(['user_id' => 9]);
		$this->actingAsApiKey();

		$response = new \XF\Http\Response();
		$response->httpCode(403);
		$this->app()->fire('app_api_complete', [$this->app(), &$response]);

		$records = $this->events->event('api.request');
		$this->assertCount(1, $records);
		$this->assertSame(['event', 'api_key_id', 'user_id', 'method', 'route', 'status'], array_keys($records[0]['context']));
		$this->assertSame(9, $records[0]['context']['user_id']);
		$this->assertSame(403, $records[0]['context']['status']);
		$this->assertIsInt($records[0]['context']['api_key_id']);
	}
}
