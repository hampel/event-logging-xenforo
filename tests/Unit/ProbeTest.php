<?php namespace Tests\Unit;

use Hampel\EventLogging\Cli\RendersReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * probe() is what lets a validate command carry on past a check that throws.
 */
class ProbeTest extends TestCase
{
	/**
	 * @return array{0: int, 1: string, 2: mixed} the exit code, the output and what probe() returned
	 */
	private function runProbe(callable $check): array
	{
		$command = new class($check) extends Command
		{
			use RendersReport;

			/** @var callable */
			private $check;

			public $returned = 'not run';

			public function __construct(callable $check)
			{
				$this->check = $check;
				parent::__construct('probe-test:validate');
			}

			protected function errorLogPrefix(): string
			{
				return 'ProbeTest: ';
			}

			protected function execute(InputInterface $input, OutputInterface $output)
			{
				$this->report = $output;
				$this->returned = $this->probe('first check', $this->check);
				$this->checkOk('second check', 'still ran');

				return $this->checkExitCode();
			}
		};

		$tester = new CommandTester($command);
		$code = $tester->execute([], ['decorated' => false]);

		return [$code, $tester->getDisplay(), $command->returned];
	}

	public function test_a_check_that_returns_hands_its_value_back()
	{
		$this->fakesErrors();

		[$code, $output, $returned] = $this->runProbe(function () { return '/path/to/log'; });

		$this->assertSame(0, $code);
		$this->assertSame('/path/to/log', $returned);
		$this->assertStringNotContainsString('[fail]', $output);
		$this->assertNoExceptionsLogged();
	}

	public function test_a_check_that_throws_is_a_failed_row_and_the_run_carries_on()
	{
		$this->fakesErrors();

		[$code, $output, $returned] = $this->runProbe(function () { throw new \RuntimeException("cannot connect\nsecond line"); });

		$this->assertSame(1, $code);
		$this->assertNull($returned);
		$this->assertMatchesRegularExpression('/\[fail\] first check +RuntimeException: cannot connect$/m', $output);
		$this->assertMatchesRegularExpression('/\[ ok \] second check +still ran/', $output);
	}

	/**
	 * The row has room for one line; the server error log gets the exception whole.
	 */
	public function test_a_check_that_throws_is_logged()
	{
		$this->fakesErrors();

		$this->runProbe(function () { throw new \RuntimeException('cannot connect'); });

		$this->assertExceptionLoggedTimes(\RuntimeException::class, 1);
	}

	/**
	 * The row is prefixed so the error log mirror passes over it, and names the command and check.
	 */
	public function test_what_is_logged_carries_the_prefix_the_command_and_the_check()
	{
		$this->fakesErrors();

		$this->runProbe(function () { throw new \RuntimeException('cannot connect'); });

		$logged = array_column($this->getExceptions(), 'message');
		$this->assertSame(['ProbeTest: probe-test:validate - first check: cannot connect'], $logged);
	}

	public function test_an_error_is_caught_as_well_as_an_exception()
	{
		$this->fakesErrors();

		[$code, $output] = $this->runProbe(function () { throw new \TypeError('wrong type'); });

		$this->assertSame(1, $code);
		$this->assertStringContainsString('[fail] first check', $output);
	}
}
