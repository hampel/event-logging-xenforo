<?php namespace Tests\Feature;

use Hampel\EventLogging\Emitter;
use Hampel\EventLogging\ErrorLogMirror;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class ErrorLogMirrorTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * Every test starts with no position, whatever the install holds: on a forum with the errors
	 * group on, the cron entry has already recorded one. The transaction puts it back.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->app()->simpleCache()->deleteValue('Hampel/EventLogging', ErrorLogMirror::CACHE_KEY);
		$this->app()->simpleCache()->deleteValue('Hampel/EventLogging', ErrorLogMirror::DATE_KEY);
	}

	private function mirror(): ErrorLogMirror
	{
		return new ErrorLogMirror($this->app());
	}

	private function logError(string $message, string $type = 'ErrorException', string $file = 'src/addons/Foo/Bar.php', int $line = 10, int $date = 1893456000): int
	{
		$db = $this->app()->db();
		$db->insert('xf_error_log', [
			'exception_date' => $date,
			'user_id' => 5,
			'ip_address' => '',
			'exception_type' => $type,
			'message' => $message,
			'filename' => $file,
			'line' => $line,
			'trace_string' => '#0 secret-in-the-trace',
			'request_state' => json_encode(['url' => '/', '_POST' => ['password' => 'correct-horse']]),
		]);

		return (int) $db->lastInsertId();
	}

	private function position()
	{
		return $this->app()->simpleCache()->getValue('Hampel/EventLogging', ErrorLogMirror::CACHE_KEY);
	}

	/**
	 * Turning the group on must not send everything already in the error log.
	 */
	public function test_the_first_run_starts_from_the_newest_error_and_sends_nothing()
	{
		$this->enableGroup('errors');
		$id = $this->logError('Before the mirror was on');

		$this->assertSame(0, $this->mirror()->run());

		$this->assertSame([], $this->events->records);
		$this->assertSame($id, $this->position());
	}

	public function test_a_new_error_is_sent_at_error_with_the_fact_and_not_the_row()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$id = $this->logError('Template public:thread_view failed');

		$this->assertSame(1, $this->mirror()->run());

		$records = $this->events->event('error.logged');
		$this->assertCount(1, $records);
		$this->assertSame('error', $records[0]['level']);
		$this->assertSame('Server error logged', $records[0]['message']);
		$this->assertSame([
			'event' => 'error.logged',
			'fingerprint' => 'ErrorException:src/addons/Foo/Bar.php:10:' . substr(md5('Template public:thread_view failed'), 0, 12),
			'error_id' => $id,
			'last_error_id' => $id,
			'count' => 1,
			'exception_type' => 'ErrorException',
			'error_message' => 'Template public:thread_view failed',
			'filename' => 'src/addons/Foo/Bar.php',
			'line' => 10,
			'user_id' => 5,
			'error_date' => '2030-01-01T00:00:00+00:00',
		], $records[0]['context']);

		$this->assertStringNotContainsString('correct-horse', $this->emitted(), 'request_state is not sent');
		$this->assertStringNotContainsString('secret-in-the-trace', $this->emitted(), 'the trace is not sent');
		$this->assertSame($id, $this->position());
	}

	public function test_an_error_is_sent_once()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$this->logError('Once');

		$this->mirror()->run();
		$this->assertSame(0, $this->mirror()->run());

		$this->assertCount(1, $this->events->event('error.logged'));
	}

	/**
	 * A broken template logs the same exception on every page view.
	 */
	public function test_a_storm_of_the_same_error_is_one_record_with_a_count()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$first = $this->logError('Same');
		$this->logError('Same');
		$other = $this->logError('Different', 'TypeError', 'src/addons/Foo/Baz.php', 3);
		$last = $this->logError('Same');

		$this->assertSame(2, $this->mirror()->run());

		$records = $this->events->event('error.logged');
		$this->assertSame(3, $records[0]['context']['count']);
		$this->assertSame($first, $records[0]['context']['error_id']);
		$this->assertSame($last, $records[0]['context']['last_error_id']);
		$this->assertSame(1, $records[1]['context']['count']);
		$this->assertSame($other, $records[1]['context']['error_id']);
	}

	/**
	 * Everything \XF::logError() writes has the same type, file and line - XenForo builds the
	 * exception itself - so those three alone would merge unrelated errors into one record.
	 */
	public function test_different_messages_from_the_same_place_are_different_errors()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$this->logError('Payment provider rejected the callback', 'ErrorException', 'src/XF/Error.php', 77);
		$this->logError('Search index is unreachable', 'ErrorException', 'src/XF/Error.php', 77);
		$this->logError('Search index is unreachable', 'ErrorException', 'src/XF/Error.php', 77);

		$this->assertSame(2, $this->mirror()->run());

		$records = $this->events->event('error.logged');
		$this->assertSame('Payment provider rejected the callback', $records[0]['context']['error_message']);
		$this->assertSame(1, $records[0]['context']['count']);
		$this->assertSame('Search index is unreachable', $records[1]['context']['error_message']);
		$this->assertSame(2, $records[1]['context']['count']);
	}

	/**
	 * The Monolog add-on's email and Slack outputs skip a record whose fingerprint they sent
	 * recently. So the same error must keep its fingerprint from one run to the next, and a
	 * different one must not share it - every error here has the same message.
	 */
	public function test_the_fingerprint_is_the_same_for_a_repeat_and_different_for_another_error()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$this->logError('Search index is unreachable', 'ErrorException', 'src/XF/Error.php', 77);
		$this->mirror()->run();
		$this->logError('Search index is unreachable', 'ErrorException', 'src/XF/Error.php', 77);
		$this->logError('Payment provider rejected the callback', 'ErrorException', 'src/XF/Error.php', 77);
		$this->mirror()->run();

		$prints = array_column(array_column($this->events->event('error.logged'), 'context'), 'fingerprint');
		$this->assertCount(3, $prints);
		$this->assertSame($prints[0], $prints[1], 'a repeat in a later run, with a new error_id');
		$this->assertNotSame($prints[0], $prints[2], 'another message from the same place');
	}

	/**
	 * The point of the fingerprint, through the real Monolog add-on with email on: a different
	 * error in the next run is emailed, and a repeat of one already emailed is not.
	 */
	public function test_monolog_emails_a_different_error_and_skips_a_repeat()
	{
		// the deduplication store lives in XenForo's temp directory; give this test its own
		$internalData = sys_get_temp_dir() . '/eventlogging-test-' . bin2hex(random_bytes(8));
		mkdir($internalData);
		$this->setConfig('internalDataPath', $internalData);
		$this->setConfig('monolog', ['file' => false, 'slack' => false, 'email' => ['to' => 'logs@example.com', 'level' => 'error', 'dedup' => 300]]);
		$this->fakesMail();
		Emitter::setLogger(null);
		$this->enableGroup('errors');

		try
		{
			$this->mirror()->run();

			$this->logError('Search index is unreachable', 'ErrorException', 'src/XF/Error.php', 77);
			$this->mirror()->run();
			Emitter::logger()->close();
			$this->assertMailSentTimes(1);

			$this->logError('Payment provider rejected the callback', 'ErrorException', 'src/XF/Error.php', 77);
			$this->mirror()->run();
			Emitter::logger()->close();
			$this->assertMailSentTimes(2);

			$this->logError('Search index is unreachable', 'ErrorException', 'src/XF/Error.php', 77);
			$this->mirror()->run();
			Emitter::logger()->close();
			$this->assertMailSentTimes(2);
		}
		finally
		{
			foreach (new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($internalData, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			) AS $file)
			{
				$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
			}
			rmdir($internalData);
		}
	}

	/**
	 * A row about a failure to log, sent at error, is handled, fails the same way and is logged
	 * again - one round per run.
	 */
	public function test_the_logging_add_ons_own_failures_are_not_sent()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$this->logError('Monolog: posting to Slack failed - timed out');
		$last = $this->logError('EventLogging: an event could not be logged - disk full');

		$this->assertSame(0, $this->mirror()->run());

		$this->assertSame([], $this->events->records);
		$this->assertSame($last, $this->position(), 'they are passed over, not retried');
	}

	public function test_a_long_message_is_cut()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$this->logError(str_repeat('x', 5000));

		$this->mirror()->run();

		$this->assertSame(ErrorLogMirror::MESSAGE_LENGTH, strlen($this->events->event('error.logged')[0]['context']['error_message']));
	}

	public function test_turning_the_group_off_forgets_the_position()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$this->assertNotNull($this->position());

		$this->setOption('eventLoggingErrors', false);
		$this->logError('While it was off');
		$this->mirror()->run();

		$this->assertNull($this->position());
		$this->assertSame([], $this->events->records);
	}

	/**
	 * An administrator clearing the newest errors leaves the last one sent past the end of the
	 * table. What is left was all sent before, and must not be sent again.
	 */
	public function test_deleting_the_newest_rows_replays_nothing()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$old = $this->logError('Sent before', 'ErrorException', 'src/addons/Foo/Bar.php', 10, 1893456000);
		$this->logError('Sent and then deleted', 'TypeError', 'src/addons/Foo/Baz.php', 3, 1893456100);
		$newest = $this->logError('Also deleted', 'TypeError', 'src/addons/Foo/Qux.php', 4, 1893456200);
		$this->mirror()->run();
		$this->assertCount(3, $this->events->event('error.logged'));

		$this->app()->db()->delete('xf_error_log', 'error_id > ?', $old);
		$this->assertGreaterThan($old, $this->position(), 'the last one sent is now past the end of the table');

		$this->assertSame(0, $this->mirror()->run());
		$this->assertCount(3, $this->events->event('error.logged'), 'nothing was sent again');
		$this->assertSame($old, $this->position(), 'it resumes from the end of the table');

		// and the next error after that is still sent
		$this->logError('After the deletion', 'ErrorException', 'src/addons/Foo/New.php', 1, 1893456300);
		$this->assertSame(1, $this->mirror()->run());
		$this->assertSame('After the deletion', $this->events->event('error.logged')[3]['context']['error_message']);
	}

	/**
	 * Emptied and its counter reset, the table's ids start again below the last one sent, and
	 * every row in it is new. Its dates are what say so.
	 */
	public function test_an_emptied_table_sends_what_is_newer_than_the_last_row_sent()
	{
		$this->enableGroup('errors');
		$id = $this->logError('After the log was cleared', 'ErrorException', 'src/addons/Foo/Bar.php', 10, 1893456500);
		$this->app()->simpleCache()->setValue('Hampel/EventLogging', ErrorLogMirror::CACHE_KEY, $id + 1000);
		$this->app()->simpleCache()->setValue('Hampel/EventLogging', ErrorLogMirror::DATE_KEY, 1893456400);

		$this->assertSame(1, $this->mirror()->run());

		$this->assertSame('After the log was cleared', $this->events->event('error.logged')[0]['context']['error_message']);
		$this->assertSame($id, $this->position());
	}

	/**
	 * A position kept by a release that did not keep a date: resume from the end of the table,
	 * rather than replay it.
	 */
	public function test_a_position_with_no_date_past_the_end_of_the_table_resumes_from_the_end()
	{
		$this->enableGroup('errors');
		$id = $this->logError('Already sent by an earlier release');
		$this->app()->simpleCache()->setValue('Hampel/EventLogging', ErrorLogMirror::CACHE_KEY, $id + 1000);
		$this->app()->simpleCache()->deleteValue('Hampel/EventLogging', ErrorLogMirror::DATE_KEY);

		$this->assertSame(0, $this->mirror()->run());

		$this->assertSame([], $this->events->records);
		$this->assertSame($id, $this->position());
	}

	public function test_turning_the_group_off_forgets_the_date_too()
	{
		$this->enableGroup('errors');
		$this->mirror()->run();
		$this->setOption('eventLoggingErrors', false);

		$this->mirror()->run();

		$this->assertFalse($this->app()->simpleCache()->keyExists('Hampel/EventLogging', ErrorLogMirror::DATE_KEY));
	}

	public function test_the_cron_entry_runs_the_mirror()
	{
		$entry = $this->app()->em()->find('XF:CronEntry', 'eventLoggingErrorLog');

		$this->assertNotNull($entry);
		$this->assertTrue($entry->active);
		$this->assertTrue(is_callable([$entry->cron_class, $entry->cron_method]));
	}
}
