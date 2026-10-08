<?php namespace Tests\Feature;

use Hampel\EventLogging\Cli\Command\Config;
use Hampel\EventLogging\Cli\Command\Validate;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class CommandsTest extends TestCase
{
	/**
	 * XenForo loads every add-on's command classes to list them, so one that cannot load takes down
	 * every command in cmd.php, not only its own.
	 */
	public function test_both_commands_are_valid_xenforo_commands()
	{
		$runner = new \XF\Cli\Runner();
		$isValid = new \ReflectionMethod($runner, 'isValidCommandClass');
		$isValid->setAccessible(true);

		$this->assertTrue($isValid->invoke($runner, Config::class));
		$this->assertTrue($isValid->invoke($runner, Validate::class));
	}

	public function test_config_shows_each_group_and_emits_nothing()
	{
		$this->enableGroup('spam');

		[$code, $output] = $this->runCommand(new Config());

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/spam \.+ on - spam\.triggered/', $output);
		$this->assertMatchesRegularExpression('/admin \.+ off/', $output);
		$this->assertSame([], $this->events->records);
	}

	public function test_validate_unattended_writes_no_probe()
	{
		[$code, $output] = $this->runCommand(new Validate(), ['--unattended' => true]);

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[    \] probe record +skipped - --unattended/', $output);
		$this->assertMatchesRegularExpression('/\[warn\] event groups +none is on/', $output);
		$this->assertStringContainsString('Validated, with warnings', $output);
		$this->assertSame([], $this->events->records);
	}

	public function test_validate_writes_one_probe_marked_as_a_probe()
	{
		$this->runCommand(new Validate());

		$this->assertCount(1, $this->events->records);
		$this->assertSame('info', $this->events->records[0]['level']);
		$this->assertTrue($this->events->records[0]['context']['probe']);
	}

	/**
	 * Event records are Info and the log file's default level is Warning, so out of the box an
	 * event is written nowhere - which validate must say, with the line that fixes it.
	 */
	public function test_validate_warns_when_the_log_file_does_not_take_info_from_the_events_channel()
	{
		$this->setConfig('monolog', ['file' => ['level' => 'warning']]);

		[$code, $output] = $this->runCommand(new Validate(), ['--unattended' => true]);

		$this->assertSame(0, $code, 'a warning does not fail');
		$this->assertMatchesRegularExpression('/\[warn\] level +Warning - events are Info and are not written/', $output);
		$this->assertStringContainsString("['channels']['events'] = 'info'", $output);
	}

	public function test_validate_accepts_a_level_set_for_the_events_channel_alone()
	{
		$this->setConfig('monolog', ['file' => ['level' => 'warning', 'format' => 'json', 'channels' => ['events' => 'info']]]);

		[$code, $output] = $this->runCommand(new Validate(), ['--unattended' => true]);

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[ ok \] level +Info, for the events channel alone/', $output);
		$this->assertMatchesRegularExpression('/\[ ok \] format +json/', $output);
	}

	/**
	 * An event logger with every group off logs nothing: it works, and it is not doing its job.
	 * Without --strict that is still exit 0, which is this add-on's state straight after install.
	 */
	public function test_no_group_on_is_a_warning_that_only_strict_turns_into_an_exit_code()
	{
		$this->setConfig('monolog', ['file' => ['format' => 'json']]);

		[$code] = $this->runCommand(new Validate(), ['--unattended' => true]);
		$this->assertSame(0, $code);

		[$code, $output] = $this->runCommand(new Validate(), ['--unattended' => true, '--strict' => true]);
		$this->assertSame(2, $code);
		$this->assertMatchesRegularExpression('/\[warn\] event groups/', $output);
	}

	public function test_strict_exits_0_when_nothing_warns()
	{
		$this->setConfig('monolog', ['file' => ['format' => 'json']]);
		$this->enableGroup('spam');

		[$code, $output] = $this->runCommand(new Validate(), ['--unattended' => true, '--strict' => true]);

		$this->assertSame(0, $code, $output);
		$this->assertStringNotContainsString('[warn]', $output);
	}

	/**
	 * --strict changes the exit code and nothing else: the probe still runs unless --unattended
	 * says otherwise.
	 */
	public function test_strict_does_not_skip_the_probe()
	{
		$this->runCommand(new Validate(), ['--strict' => true]);

		$this->assertCount(1, $this->events->event('eventlogging.validate'));
	}

	/**
	 * XenForo 2.2's console fork throws on `gray`, which takes a command down at its first
	 * annotation. Grey goes through RendersReport::muted(), which falls back where it is missing.
	 */
	public function test_no_command_writes_grey_except_through_muted()
	{
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/Cli')) AS $file)
		{
			if ($file->getExtension() !== 'php')
			{
				continue;
			}

			$source = file_get_contents($file->getPathname());
			$this->assertSame(0, preg_match('/<fg=gr[ae]y/', $source), $file->getFilename() . ' writes <fg=gray> directly');
		}
	}

	/**
	 * The error log mirror skips rows that begin with this add-on's prefix. A row about a failure
	 * to log that lacks it is mirrored at error, handled, fails the same way and is logged again.
	 */
	public function test_every_server_error_log_call_carries_the_prefix()
	{
		$calls = 0;
		$root = dirname(__DIR__, 2);
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) AS $file)
		{
			$path = $file->getPathname();
			if ($file->getExtension() !== 'php' || preg_match('#/(vendor|tests|_build|_releases|_output)/#', $path))
			{
				continue;
			}

			foreach (file($path) AS $number => $line)
			{
				if (!preg_match('/\\\\XF::log(Error|Exception)\(/', $line) || preg_match('#^\s*(//|\*)#', $line))
				{
					continue;
				}

				$calls++;
				$this->assertMatchesRegularExpression(
					'/ERROR_PREFIX|errorLogPrefix\(\)/',
					$line,
					basename($path) . ':' . ($number + 1) . ' logs to the server error log without the prefix'
				);
			}
		}

		$this->assertSame(2, $calls, 'the emitter and the report trait - a new call belongs in this count');
	}

	public function test_both_commands_return_the_prefix_the_mirror_skips()
	{
		foreach ([new Config(), new Validate()] AS $command)
		{
			$prefix = (new \ReflectionMethod($command, 'errorLogPrefix'))->invoke($command);

			$this->assertSame('EventLogging: ', $prefix);
			$this->assertContains(rtrim($prefix), \Hampel\EventLogging\ErrorLogMirror::SKIP_PREFIXES);
		}
	}

	public function test_the_probe_reports_on_the_server_error_log()
	{
		[, $output] = $this->runCommand(new Validate());

		$this->assertMatchesRegularExpression('/\[ ok \] server error log +nothing logged while the probe was written/', $output);
	}

	private function runCommand($command, array $input = []): array
	{
		$tester = new CommandTester($command);
		$code = $tester->execute($input, ['decorated' => false]);

		return [$code, $tester->getDisplay()];
	}
}
