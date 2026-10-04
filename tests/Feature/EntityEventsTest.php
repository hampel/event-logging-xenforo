<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The events XenForo writes through an entity: the moderator log, bans, failed logins and the
 * payment provider log. Each is driven through XenForo's own write, so a listener whose hint
 * names the wrong class fails here.
 */
class EntityEventsTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function moderatorLog(): \XF\Entity\ModeratorLog
	{
		return $this->createEntity('XF:ModeratorLog', [
			'user_id' => 7,
			'content_type' => 'post',
			'content_id' => 90211,
			'content_user_id' => 12,
			'content_username' => 'Alice Example',
			'content_title' => 'A private matter',
			'content_url' => 'https://example.com/posts/90211/',
			'discussion_content_type' => 'thread',
			'discussion_content_id' => 55,
			'action' => 'delete_soft',
			'action_params' => ['reason' => 'breach of rule four', 'old_title' => 'An earlier name'],
		]);
	}

	public function test_a_moderator_log_entry_emits_moderator_action()
	{
		$this->enableGroup('moderation');

		$log = $this->moderatorLog();

		$records = $this->events->event('moderator.action');
		$this->assertCount(1, $records);
		$this->assertSame('info', $records[0]['level']);
		$this->assertSame('Moderator action', $records[0]['message']);
		$this->assertSame([
			'event' => 'moderator.action',
			'moderator_log_id' => $log->moderator_log_id,
			'actor_user_id' => 7,
			'content_type' => 'post',
			'content_id' => 90211,
			'content_user_id' => 12,
			'content_username' => 'Alice Example',
			'content_title' => 'A private matter',
			'discussion_content_type' => 'thread',
			'discussion_content_id' => 55,
			'action' => 'delete_soft',
			'reason' => 'breach of rule four',
		], $records[0]['context']);
	}

	/**
	 * The reason is the one action parameter taken; the rest are per-action detail.
	 */
	public function test_a_moderator_action_carries_its_reason_and_no_other_parameter()
	{
		$this->enableGroup('moderation');

		$this->moderatorLog();

		$this->assertStringContainsString('rule four', $this->emitted());
		$this->assertStringNotContainsString('earlier name', $this->emitted());
	}

	public function test_a_moderator_action_with_no_reason_has_a_null_reason()
	{
		$this->assertNull(\Hampel\EventLogging\Listener::reason([]));
		$this->assertNull(\Hampel\EventLogging\Listener::reason(['reason' => '']));
		$this->assertNull(\Hampel\EventLogging\Listener::reason(''));
		$this->assertSame('spam', \Hampel\EventLogging\Listener::reason(['reason' => 'spam']));
	}

	public function test_nothing_is_emitted_for_the_moderator_log_while_the_group_is_off()
	{
		$this->moderatorLog();

		$this->assertSame([], $this->events->records);
	}

	public function test_a_ban_emits_banned_then_updated_then_lifted()
	{
		// XenForo refuses to ban a member who does not exist, or who is staff
		$user = $this->app()->finder('XF:User')
			->where(['is_admin' => 0, 'is_moderator' => 0, 'is_banned' => 0, 'user_state' => 'valid'])
			->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('This install has no ordinary member to ban.');
		}
		$this->enableGroup('moderation');

		$ban = $this->createEntity('XF:UserBan', [
			'user_id' => $user->user_id,
			'ban_user_id' => 1,
			'end_date' => 0,
			'user_reason' => 'spamming the classifieds',
		]);

		$banned = $this->events->event('user.banned');
		$this->assertCount(1, $banned);
		$this->assertSame([
			'event' => 'user.banned',
			'user_id' => $user->user_id,
			'actor_user_id' => 1,
			'permanent' => true,
			'end_date' => null,
			'triggered' => false,
			'reason' => 'spamming the classifieds',
		], $banned[0]['context']);

		$ban->end_date = 1893456000;
		$ban->save();

		$updated = $this->events->event('user.ban_updated');
		$this->assertCount(1, $updated);
		$this->assertFalse($updated[0]['context']['permanent']);
		$this->assertSame('2030-01-01T00:00:00+00:00', $updated[0]['context']['end_date']);

		$ban->delete();

		$lifted = $this->events->event('user.ban_lifted');
		$this->assertCount(1, $lifted);
		$this->assertSame(['event' => 'user.ban_lifted', 'user_id' => $user->user_id, 'expired' => false], $lifted[0]['context']);
	}

	public function test_a_failed_login_emits_what_was_typed_and_the_ip()
	{
		$this->enableGroup('auth');

		$this->app()->repository('XF:LoginAttempt')->logFailedLogin('nobody-by-this-name', '203.0.113.9');

		$records = $this->events->event('login.failed');
		$this->assertCount(1, $records);
		$this->assertSame([
			'event' => 'login.failed',
			'user_id' => 0,
			'login' => 'nobody-by-this-name',
			'ip' => '203.0.113.9',
		], $records[0]['context']);
	}

	public function test_a_failed_login_names_the_member_whose_account_was_tried()
	{
		$this->enableGroup('auth');
		$user = $this->app()->finder('XF:User')->order('user_id')->fetchOne();
		$this->assertNotNull($user, 'the install has a member');

		$this->app()->repository('XF:LoginAttempt')->logFailedLogin($user->username, '2001:db8::1');

		$records = $this->events->event('login.failed');
		$this->assertSame($user->user_id, $records[0]['context']['user_id']);
		$this->assertSame('2001:db8::1', $records[0]['context']['ip']);
	}

	public function test_a_payment_callback_emits_identifiers_and_never_its_details()
	{
		$this->enableGroup('payments');

		$this->app()->repository('XF:Payment')->logCallback(
			'request-key-0123456789', 'stripe', 'txn_123', 'payment', 'Payment received',
			['card_last4' => '4242', 'payer' => 'alice@example.com']
		);

		$records = $this->events->event('payment.callback');
		$this->assertCount(1, $records);
		$this->assertSame('info', $records[0]['level']);
		$this->assertSame('stripe', $records[0]['context']['provider_id']);
		$this->assertSame('payment', $records[0]['context']['log_type']);
		$this->assertSame('txn_123', $records[0]['context']['transaction_id']);
		$this->assertIsInt($records[0]['context']['provider_log_id']);

		$this->assertStringNotContainsString('4242', $this->emitted());
		$this->assertStringNotContainsString('alice@example.com', $this->emitted());
		$this->assertStringNotContainsString('request-key', $this->emitted());
	}

	public function test_a_payment_callback_error_is_a_warning()
	{
		$this->enableGroup('payments');

		$this->app()->repository('XF:Payment')->logCallback(null, 'stripe', null, 'error', 'Invalid signature', []);

		$this->assertSame('warning', $this->events->event('payment.callback')[0]['level']);
	}
}
