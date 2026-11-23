<?php namespace Hampel\EventLogging\Cli\Command;

use Hampel\EventLogging\Check\HealthCheck;
use Hampel\EventLogging\Cli\RendersReport;
use Hampel\EventLogging\Emitter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether an event emitted on this forum reaches its log: the Monolog add-on is there, a group is
 * on, the log file takes Info from the `events` channel, and a probe record arrives.
 *
 * **The checks live in `Check\HealthCheck`, and this command only renders them**, because the
 * Admin API runs the same checks over HTTP and one class is what keeps the two in step.
 *
 * The probe writes one record, marked `probe: true`, so it runs by default; --unattended skips it
 * for a deploy or cron gate nobody is watching. The checks that are facts about the configuration
 * still run.
 *
 * Exit code: 1 if any check failed, 0 otherwise - a warning does not fail, so a gate can rely on it.
 * --strict makes a warning exit 2, for a monitor that wants to hear of one; it changes nothing
 * else, and 1 still means failed.
 */
class Validate extends Command
{
	use RendersReport;

	/**
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('event-logging:validate')
			->setDescription('Check that events can reach the log')
			->addOption('unattended', null, InputOption::VALUE_NONE,
				'Skip the probe, which writes a record - for a gate nobody is watching')
			->addOption('strict', null, InputOption::VALUE_NONE,
				'Exit 2 when there are warnings and no failure - for a monitor that should hear of one');
	}

	protected function errorLogPrefix(): string
	{
		return Emitter::ERROR_PREFIX . ' ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;
		$checks = new HealthCheck(\XF::app());

		$this->checkSection('Monolog');
		$this->rows('Monolog add-on', [$checks, 'monolog']);

		$this->checkSection('Event groups');
		$this->rows('event groups', [$checks, 'groups']);

		$this->checkSection('Log file');
		$this->rows('log file', [$checks, 'logFile']);

		$this->checkSection('Server error log');
		$this->rows('error log mirror', [$checks, 'errorLog']);

		$this->checkSection('Probe');
		if ($input->getOption('unattended'))
		{
			$this->checkSkip('probe record', 'skipped - --unattended');
		}
		else
		{
			$this->rows('probe record', [$checks, 'probeRecord']);
		}

		$this->report->writeln('');
		if ($this->checksFailed())
		{
			$this->report->writeln('<error>Validation failed - events are not reaching the log as configured</error>');
		}
		else
		{
			$this->report->writeln($this->checksWarned() ? '<comment>Validated, with warnings</comment>' : '<info>Validated</info>');
		}

		return $this->checkExitCode((bool) $input->getOption('strict'));
	}

	/**
	 * Renders what one check returned, each row through the marker its outcome names. A check that
	 * throws is logged and printed as a failure under the label given here, by probe().
	 *
	 * @param callable(): list<array{0: string, 1: string, 2: string}> $check
	 */
	private function rows(string $label, callable $check): void
	{
		/** @var list<array{0: string, 1: string, 2: string}> $rows */
		$rows = (array) $this->probe($label, $check);

		foreach ($rows AS [$outcome, $rowLabel, $detail])
		{
			switch ($outcome)
			{
				case HealthCheck::OK: $this->checkOk($rowLabel, $detail); break;
				case HealthCheck::WARN: $this->checkWarn($rowLabel, $detail); break;
				case HealthCheck::FAIL: $this->checkFail($rowLabel, $detail); break;
				default: $this->checkSkip($rowLabel, $detail);
			}
		}
	}
}
