<?php namespace Hampel\EventLogging\Cli\Command;

use Hampel\EventLogging\Cli\RendersReport;
use Hampel\EventLogging\Emitter;
use Hampel\EventLogging\ErrorLogMirror;
use Hampel\EventLogging\MonologSettings;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether an event emitted on this forum reaches its log: the Monolog add-on is there, a group is
 * on, the log file takes Info from the `events` channel, and a probe record arrives.
 *
 * The probe writes one record, marked `probe: true`, so it runs by default; --unattended skips it
 * for a deploy or cron gate nobody is watching. The checks that are facts about the configuration
 * still run.
 *
 * Exit code: 1 if any check failed, 0 otherwise - a warning does not fail, so a gate can rely on it.
 */
class Validate extends Command
{
	use RendersReport;

	protected function configure()
	{
		$this
			->setName('event-logging:validate')
			->setDescription('Check that events can reach the log')
			->addOption('unattended', null, InputOption::VALUE_NONE,
				'Skip the probe, which writes a record - for a gate nobody is watching');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;

		$this->heading('Monolog');
		$this->probe('Monolog add-on', function ()
		{
			$this->monolog();
		});

		$this->heading('Event groups');
		$this->probe('event groups', function ()
		{
			$this->groups();
		});

		$this->heading('Log file');
		$this->probe('log file', function ()
		{
			$this->logFile();
		});

		$this->heading('Server error log');
		$this->probe('error log mirror', function ()
		{
			$this->errorLog();
		});

		$this->heading('Probe');
		if ($input->getOption('unattended'))
		{
			$this->checkSkip('probe record', 'skipped - --unattended');
		}
		else
		{
			$this->probe('probe record', function ()
			{
				$this->probeRecord();
			});
		}

		$this->report->writeln('');
		if ($this->failed)
		{
			$this->report->writeln('<error>Validation failed - events are not reaching the log as configured</error>');
			return 1;
		}

		$this->report->writeln($this->warned ? '<comment>Validated, with warnings</comment>' : '<info>Validated</info>');
		return 0;
	}

	private function monolog(): void
	{
		Emitter::logger() instanceof NullLogger
			? $this->checkFail('Monolog add-on', 'not installed, disabled, or older than 5.0 - no event is logged')
			: $this->checkOk('Monolog add-on', 'provides the ' . Emitter::CHANNEL . ' channel');
	}

	private function groups(): void
	{
		$on = array_filter(array_keys(Emitter::GROUPS), [Emitter::class, 'enabled']);

		$on
			? $this->checkOk('event groups', count($on) . ' of ' . count(Emitter::GROUPS) . ' on: ' . implode(', ', $on))
			: $this->checkSkip('event groups', 'none is on - nothing is emitted until one is, under Options > Monolog');
	}

	/**
	 * Event records are Info, and the log file's default level is Warning - so until the `events`
	 * channel is raised, an event that is emitted is written nowhere. A warning, not a failure:
	 * the error log mirror emits at Error and still arrives.
	 */
	private function logFile(): void
	{
		$level = MonologSettings::fileLevel();
		if ($level === null)
		{
			MonologSettings::filePath() === null
				? $this->checkSkip('level', 'file logging is off, or its settings could not be read')
				: $this->checkWarn('level', 'could not be read from the Monolog add-on');
		}
		else if ($level > MonologSettings::INFO)
		{
			$this->checkWarn('level', MonologSettings::levelName($level) . ' - events are Info and are not written. Set '
				. "\$config['monolog']['file']['channels']['" . Emitter::CHANNEL . "'] = 'info'; in config.php");
		}
		else
		{
			$this->checkOk('level', MonologSettings::levelName($level) . (MonologSettings::hasChannelLevel()
				? ', for the ' . Emitter::CHANNEL . ' channel alone' : ', for every channel'));
		}

		$format = MonologSettings::format();
		if ($format === null)
		{
			$this->checkSkip('format', 'could not be read from the Monolog add-on');
		}
		else if ($format !== 'json')
		{
			$this->checkWarn('format', "{$format} - a log collector can only read an event's fields from the JSON format");
		}
		else
		{
			$this->checkOk('format', 'json');
		}
	}

	private function errorLog(): void
	{
		if (!ErrorLogMirror::isSupported())
		{
			$this->checkSkip('error log mirror', 'not used - XenForo ' . \XF::$version . ' logs its errors through Monolog itself');
			return;
		}

		if (!Emitter::enabled('errors'))
		{
			$this->checkSkip('error log mirror', 'the errors group is off');
			return;
		}

		$cron = \XF::em()->find('XF:CronEntry', 'eventLoggingErrorLog');
		if (!$cron || !$cron->active)
		{
			$this->checkFail('cron entry', 'eventLoggingErrorLog is missing or disabled - no server error is sent');
			return;
		}
		$this->checkOk('cron entry', 'active');

		$cache = \XF::app()->simpleCache()->getSet('Hampel/EventLogging');
		if (!$cache->keyExists(ErrorLogMirror::CACHE_KEY))
		{
			$this->checkOk('position', 'not started - the next cron run begins from the newest error');
			return;
		}

		$last = (int) $cache->getValue(ErrorLogMirror::CACHE_KEY);
		$waiting = (int) \XF::db()->fetchOne('SELECT COUNT(*) FROM xf_error_log WHERE error_id > ?', $last);

		$waiting > ErrorLogMirror::BATCH
			? $this->checkWarn('position', "error #{$last} - {$waiting} waiting, more than one run sends; is cron running?")
			: $this->checkOk('position', "error #{$last} - {$waiting} waiting for the next run");
	}

	/**
	 * One record straight to the channel, whichever groups are on, found again in the log file by
	 * its run id. `probe: true` is there for an alert to exclude.
	 */
	private function probeRecord(): void
	{
		$logger = Emitter::logger();
		if ($logger instanceof NullLogger)
		{
			$this->checkSkip('probe record', 'no logger to write to');
			return;
		}

		$run = bin2hex(random_bytes(4));
		$path = MonologSettings::filePath();
		$isFile = $path !== null && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $path);
		$offset = $isFile && is_file($path) ? (int) filesize($path) : 0;

		$logger->info('Event logging probe', ['event' => 'eventlogging.validate', 'probe' => true, 'run' => $run]);
		$this->checkOk('probe record', "written to the " . Emitter::CHANNEL . " channel at Info, run {$run}");

		$level = MonologSettings::fileLevel();
		if (!$isFile || $level === null)
		{
			$this->checkSkip('log file', 'no log file to look in');
			return;
		}

		clearstatcache(true, $path);
		$found = is_file($path) && strpos((string) file_get_contents($path, false, null, $offset), $run) !== false;

		if ($level > MonologSettings::INFO)
		{
			$found
				? $this->checkFail('log file', 'the probe arrived, though the level says Info is not written')
				: $this->checkSkip('log file', 'the probe was not written, as expected at ' . MonologSettings::levelName($level));
			return;
		}

		$found
			? $this->checkOk('log file', "the probe arrived in {$path}")
			: $this->checkFail('log file', "the probe did not arrive in {$path}");
	}

	/**
	 * No check may end the run: a validation that stops at its first problem hides the rest.
	 */
	private function probe(string $label, callable $probe): void
	{
		try
		{
			$probe();
		}
		catch (\Throwable $e)
		{
			$this->checkFail($label, get_class($e) . ': ' . strtok($e->getMessage(), "\n"));
		}
	}
}
