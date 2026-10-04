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
		$this->assertMatchesRegularExpression('/\[    \] event groups +none is on/', $output);
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

	private function runCommand($command, array $input = []): array
	{
		$tester = new CommandTester($command);
		$code = $tester->execute($input, ['decorated' => false]);

		return [$code, $tester->getDisplay()];
	}
}
