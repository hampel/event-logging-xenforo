<?php namespace Tests\Feature;

use Hampel\EventLogging\Cli\Command\Config;
use Hampel\EventLogging\Cli\Command\Validate;
use Hampel\EventLogging\Emitter;
use Hampel\EventLogging\ErrorLogMirror;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * XenForo checks an add-on's requirements when it is installed or upgraded, and not afterwards.
 * So the Monolog add-on can be disabled while this one stays enabled, with every group on - and
 * every listener and class extension here then runs inside a moderator's action, a login and an
 * email with nothing to log to. None of them may fail because of it.
 */
class MonologAbsentTest extends TestCase
{
	use UsesDatabaseTransactions;

	/** @var array this add-on alone: the one it requires is not loaded */
	protected $addonsToLoad = ['Hampel/EventLogging'];

	protected function setUp(): void
	{
		parent::setUp();

		// the real logger, whatever that turns out to be - not the recording one
		Emitter::setLogger(null);

		foreach (array_keys(Emitter::GROUPS) AS $group)
		{
			$this->enableGroup($group);
		}
	}

	public function test_the_monolog_add_on_is_not_loaded()
	{
		$this->assertFalse($this->app()->container()->offsetExists('monolog'));
	}

	public function test_events_go_to_a_null_logger()
	{
		$this->assertInstanceOf(NullLogger::class, Emitter::logger());
	}

	public function test_an_entity_event_completes_and_reports_nothing()
	{
		$this->fakesErrors();

		$this->app()->repository('XF:LoginAttempt')->logFailedLogin('someone', '203.0.113.9');

		$this->assertDatabaseHas('xf_login_attempt', ['login' => 'someone']);
		$this->assertNoExceptionsLogged();
	}

	public function test_a_class_extension_event_completes_and_reports_nothing()
	{
		$this->fakesErrors();

		$logId = $this->app()->repository('XF:AdminLog')->logAdminRequest(3, '/admin.php?users/1/save', [], '203.0.113.9');

		$this->assertDatabaseHas('xf_admin_log', ['admin_log_id' => $logId]);
		$this->assertNoExceptionsLogged();
	}

	public function test_an_email_is_still_sent()
	{
		$this->fakesMail();
		$this->fakesErrors();

		$this->app()->mailer()->newMail()->setTo('alice@example.com')->setContent('Subject', 'Body')->send();

		$this->assertMailSentTimes(1);
		$this->assertNoExceptionsLogged();
	}

	/**
	 * It still moves its position: the errors are not held back for when Monolog returns.
	 */
	public function test_the_error_log_mirror_runs_and_reports_nothing()
	{
		$cache = $this->app()->simpleCache();
		$cache->deleteValue('Hampel/EventLogging', ErrorLogMirror::CACHE_KEY);
		$mirror = new ErrorLogMirror($this->app());
		$mirror->run();

		$this->app()->db()->insert('xf_error_log', [
			'exception_date' => \XF::$time,
			'user_id' => 0,
			'ip_address' => '',
			'exception_type' => 'ErrorException',
			'message' => 'While Monolog was disabled',
			'filename' => 'src/XF/Error.php',
			'line' => 77,
			'trace_string' => '',
			'request_state' => '[]',
		]);
		$id = (int) $this->app()->db()->lastInsertId();

		$this->assertSame(1, $mirror->run());
		$this->assertSame($id, $cache->getValue('Hampel/EventLogging', ErrorLogMirror::CACHE_KEY));
		$this->assertSame(0, (int) $this->app()->db()->fetchOne(
			'SELECT COUNT(*) FROM xf_error_log WHERE error_id > ?', $id
		), 'nothing was added to the server error log');
	}

	public function test_validate_fails_naming_monolog_and_does_not_crash()
	{
		$tester = new CommandTester(new Validate());
		$code = $tester->execute([], ['decorated' => false]);
		$output = $tester->getDisplay();

		$this->assertSame(1, $code);
		$this->assertMatchesRegularExpression('/\[fail\] Monolog add-on +not installed, disabled, or older than/', $output);
		$this->assertMatchesRegularExpression('/\[    \] probe record +no logger to write to/', $output);
		$this->assertStringContainsString('Validation failed', $output);
	}

	/**
	 * A disabled add-on's options stay in the database, so the Monolog add-on's log path and level
	 * can still be read - and would describe a file nothing is being written to.
	 */
	public function test_config_says_nothing_is_logged_and_shows_no_log_file()
	{
		$tester = new CommandTester(new Config());
		$code = $tester->execute([], ['decorated' => false]);
		$output = $tester->getDisplay();

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/moderation \.+ on/', $output);
		$this->assertMatchesRegularExpression('/Monolog add-on \.+ not installed, disabled, or older than/', $output);
		$this->assertStringNotContainsString('monolog.log', $output);
		$this->assertDoesNotMatchRegularExpression('/level \.+/', $output);
	}
}
