<?php namespace Tests\Feature;

use Hampel\EventLogging\Emitter;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class EmitterTest extends TestCase
{
	/**
	 * Installing the add-on must not be the thing that starts recording.
	 */
	public function test_every_group_is_off_by_default()
	{
		$options = $this->app()->finder('XF:Option')->where('addon_id', 'Hampel/EventLogging')->fetch();

		// one per group, and one that narrows the admin group
		$this->assertCount(count(Emitter::GROUPS) + 1, $options);
		foreach ($options AS $option)
		{
			$this->assertEmpty($option->default_value, "{$option->option_id} defaults to off");
		}
	}

	/**
	 * The options only make sense beside Monolog's, so they are on its page, after its own.
	 */
	public function test_every_option_is_on_the_monolog_options_page_after_monologs_own()
	{
		$group = $this->app()->em()->find('XF:OptionGroup', 'monolog');
		$this->assertNotNull($group);
		$this->assertSame('Hampel/Monolog', $group->addon_id);

		$orders = $this->app()->db()->fetchPairs('
			SELECT o.option_id, r.display_order
			FROM xf_option_group_relation AS r
			INNER JOIN xf_option AS o ON (o.option_id = r.option_id)
			WHERE r.group_id = ? AND o.addon_id = ?
			ORDER BY r.display_order
		', ['monolog', 'Hampel/EventLogging']);
		$this->assertCount(count(Emitter::GROUPS) + 1, $orders);
		$this->assertSame(
			['eventLoggingAdmin', 'eventLoggingAdminSkipKeepAlive'],
			array_slice(array_keys(array_filter($orders, function ($order) { return $order >= 2020 && $order < 2030; })), 0),
			'the keep-alive option sits directly under the admin group it narrows'
		);

		$monologsLast = (int) $this->app()->db()->fetchOne('
			SELECT MAX(r.display_order)
			FROM xf_option_group_relation AS r
			INNER JOIN xf_option AS o ON (o.option_id = r.option_id)
			WHERE r.group_id = ? AND o.addon_id = ?
		', ['monolog', 'Hampel/Monolog']);
		$this->assertGreaterThan($monologsLast, min($orders));

		$this->assertSame(0, (int) $this->app()->db()->fetchOne(
			'SELECT COUNT(*) FROM xf_option_group WHERE addon_id = ?', 'Hampel/EventLogging'
		), 'this add-on has no option group of its own');
	}

	public function test_a_group_that_is_off_emits_nothing()
	{
		foreach (array_keys(Emitter::GROUPS) AS $group)
		{
			Emitter::emit($group, 'test.event', 'Test');
		}

		$this->assertSame([], $this->events->records);
	}

	public function test_an_event_carries_its_name_in_the_context_at_info()
	{
		$this->enableGroup('admin');

		Emitter::emit('admin', 'admin.action', 'Admin action', ['actor_user_id' => 1]);

		$this->assertSame([[
			'level' => 'info',
			'message' => 'Admin action',
			'context' => ['event' => 'admin.action', 'actor_user_id' => 1],
		]], $this->events->records);
	}

	public function test_an_unknown_group_emits_nothing()
	{
		Emitter::emit('nonsuch', 'test.event', 'Test');

		$this->assertSame([], $this->events->records);
	}

	/**
	 * emit() is called from inside a moderator's action and a login; neither may fail because a
	 * log file could not be written. And a request that emits a hundred events reports it once.
	 */
	public function test_a_logger_that_throws_does_not_reach_the_caller_and_is_reported_once()
	{
		$this->fakesErrors();
		$this->enableGroup('admin');
		Emitter::setLogger(new class extends AbstractLogger
		{
			public function log($level, $message, array $context = []): void
			{
				throw new \RuntimeException('disk full');
			}
		});

		Emitter::emit('admin', 'admin.action', 'Admin action');
		Emitter::emit('admin', 'admin.action', 'Admin action');

		$this->assertExceptionLoggedTimes(\RuntimeException::class, 1);
	}

	public function test_the_logger_is_the_events_channel_of_the_monolog_add_on()
	{
		Emitter::setLogger(null);

		$logger = Emitter::logger();

		$this->assertInstanceOf(LoggerInterface::class, $logger);
		$this->assertSame($this->app()->get('monolog')->channel('events'), $logger);
	}

	/**
	 * The whole path, through the real Monolog add-on: a record on the `events` channel, as JSON,
	 * written because config.php raises that channel alone to Info.
	 */
	public function test_an_event_reaches_the_log_file_as_a_json_record_on_the_events_channel()
	{
		$file = sys_get_temp_dir() . '/eventlogging-test-' . bin2hex(random_bytes(8)) . '.log';
		$this->setConfig('monolog', ['file' => [
			'path' => $file,
			'format' => 'json',
			'level' => 'warning',
			'channels' => ['events' => 'info'],
		]]);
		Emitter::setLogger(null);
		$this->enableGroup('admin');

		try
		{
			Emitter::emit('admin', 'admin.action', 'Admin action', ['actor_user_id' => 1]);

			$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		}
		finally
		{
			@unlink($file);
		}

		$this->assertCount(1, $lines);
		$record = json_decode($lines[0], true);
		$this->assertSame('events', $record['channel']);
		$this->assertSame('INFO', $record['level_name']);
		$this->assertSame('Admin action', $record['message']);
		$this->assertSame(['event' => 'admin.action', 'actor_user_id' => 1], $record['context']);
		$this->assertArrayHasKey('site', $record['extra']);
		$this->assertArrayHasKey('request_id', $record['extra']);
	}

	/**
	 * Event names are the public API. Every one is noun.verb, lowercase, and listed in the README.
	 */
	public function test_every_event_name_is_noun_verb_and_documented()
	{
		$readme = file_get_contents(__DIR__ . '/../../README.md');

		foreach (Emitter::GROUPS AS $group => $definition)
		{
			foreach ($definition['events'] AS $event)
			{
				$this->assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/', $event);
				$this->assertStringContainsString("`{$event}`", $readme, "{$event} is in the README");
			}
		}
	}
}
