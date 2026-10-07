<?php namespace Tests\Unit;

use Hampel\EventLogging\Cli\RendersReport;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * The exit contract every validate command shares: 1 means a check failed, with or without
 * --strict, and --strict only adds 2 for warnings with no failure.
 */
class ExitCodeTest extends TestCase
{
	public static function outcomes(): array
	{
		return [
			'clean' => [[], false, 0],
			'clean, strict' => [[], true, 0],
			'skips only' => [['skip'], false, 0],
			'skips only, strict' => [['skip'], true, 0],
			'a warning' => [['warn'], false, 0],
			'a warning, strict' => [['warn'], true, 2],
			'a failure' => [['fail'], false, 1],
			'a failure, strict' => [['fail'], true, 1],
			'a warning and a failure' => [['warn', 'fail'], false, 1],
			'a warning and a failure, strict' => [['warn', 'fail'], true, 1],
		];
	}

	#[DataProvider('outcomes')]
	public function test_the_exit_code(array $rows, bool $strict, int $expected)
	{
		$command = new class($rows) extends Command
		{
			use RendersReport;

			/** @var string[] */
			private $rows;

			public function __construct(array $rows)
			{
				$this->rows = $rows;
				parent::__construct('exit-test:validate');
			}

			protected function configure()
			{
				$this->addOption('strict', null, InputOption::VALUE_NONE);
			}

			protected function errorLogPrefix(): string
			{
				return 'ExitCodeTest: ';
			}

			protected function execute(InputInterface $input, OutputInterface $output)
			{
				$this->report = $output;
				$this->checkOk('always', 'proved');
				foreach ($this->rows AS $row)
				{
					$row === 'warn' ? $this->checkWarn('row') : ($row === 'fail' ? $this->checkFail('row') : $this->checkSkip('row'));
				}

				return $this->checkExitCode((bool) $input->getOption('strict'));
			}
		};

		$tester = new CommandTester($command);

		$this->assertSame($expected, $tester->execute($strict ? ['--strict' => true] : []));
	}
}
