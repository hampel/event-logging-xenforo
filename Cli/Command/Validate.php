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
 * --strict makes a warning exit 2, for a monitor that wants to hear of one; it changes nothing
 * else, and 1 still means failed.
 */
class Validate extends Command
{
	use RendersReport;

	private const PROBE_MESSAGE = 'Event logging probe';

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

		$this->checkSection('Monolog');
		$this->probe('Monolog add-on', function ()
		{
			$this->monolog();
		});

		$this->checkSection('Event groups');
		$this->probe('event groups', function ()
		{
			$this->groups();
		});

		$this->checkSection('Log file');
		$this->probe('log file', function ()
		{
			$this->logFile();
		});

		$this->checkSection('Server error log');
		$this->probe('error log mirror', function ()
		{
			$this->errorLog();
		});

		$this->checkSection('Probe');
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

	private function monolog(): void
	{
		Emitter::logger() instanceof NullLogger
			? $this->checkFail('Monolog add-on', 'not installed, disabled, or older than 5.0.1 - no event is logged')
			: $this->checkOk('Monolog add-on', 'provides the ' . Emitter::CHANNEL . ' channel');
	}

	private function groups(): void
	{
		$on = array_filter(array_keys(Emitter::GROUPS), [Emitter::class, 'enabled']);

		$on
			? $this->checkOk('event groups', count($on) . ' of ' . count(Emitter::GROUPS) . ' on: ' . implode(', ', $on))
			: $this->checkWarn('event groups', 'none is on - nothing is emitted until one is, under Options > Monolog');
	}

	/**
	 * Event records are Info, and the log file's default level is Warning - so until the `events`
	 * channel is raised, an event that is emitted is written nowhere. A warning, not a failure:
	 * the error log mirror emits at Error and still arrives.
	 */
	private function logFile(): void
	{
		if (Emitter::logger() instanceof NullLogger)
		{
			// a disabled add-on's options are still readable, and describe a file nothing writes to
			$this->checkSkip('level', 'the Monolog add-on is not available');
			$this->checkSkip('format', 'the Monolog add-on is not available');
			return;
		}

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
			$this->checkSkip('error log mirror', 'not used - this forum has no server error log table');
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

		$mirror = new ErrorLogMirror(\XF::app());
		$position = $mirror->position();
		if ($position['last'] === null)
		{
			$this->checkOk('position', 'not started - the next cron run begins from the newest error');
			return;
		}

		$waiting = $mirror->waiting();
		$at = "error #{$position['last']}";
		if ($position['pastEnd'])
		{
			// rows were deleted from the log, or it was emptied: not a fault, and the mirror
			// sends only what is newer than the last error it sent
			$at .= ", past the end of the log (#{$position['max']})";
		}

		$waiting > ErrorLogMirror::BATCH
			? $this->checkWarn('position', "{$at} - {$waiting} waiting, more than one run sends; is cron running?")
			: $this->checkOk('position', "{$at} - {$waiting} waiting for the next run");
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
		$errorsBefore = (int) \XF::db()->fetchOne('SELECT MAX(error_id) FROM xf_error_log');

		$logger->info(self::PROBE_MESSAGE, ['event' => 'eventlogging.validate', 'probe' => true, 'run' => $run]);
		$this->checkOk('probe record', "written to the " . Emitter::CHANNEL . " channel at Info, run {$run}");

		$this->probeArrived($isFile ? $path : null, $offset, $run);

		// a handler that fails quietly - an unwritable file, a refused post - reports here and
		// nowhere else
		$errors = \XF::db()->fetchAll('SELECT message FROM xf_error_log WHERE error_id > ? ORDER BY error_id', $errorsBefore);
		$errors
			? $this->checkFail('server error log', count($errors) . ' new: ' . strtok($errors[0]['message'], "\n"))
			: $this->checkOk('server error log', 'nothing logged while the probe was written');
	}

	private function probeArrived(?string $path, int $offset, string $run): void
	{
		$level = MonologSettings::fileLevel();
		if ($path === null || $level === null)
		{
			$this->checkSkip('log file', 'no log file to look in');
			return;
		}

		[$own, $others] = $this->probeLines($path, $offset, $run);
		$custom = MonologSettings::hasCustomFormatter();
		$found = $custom ? ($own + $others) > 0 : $own > 0;

		$notes = [];
		if ($custom)
		{
			$notes[] = 'custom formatter - found by its run id alone';
		}
		else if ($others)
		{
			$notes[] = "{$others} other line" . ($others === 1 ? ' mentions' : 's mention')
				. ' this run - another record quoting it - not counted';
		}
		$note = $notes ? ' (' . implode('; ', $notes) . ')' : '';

		if ($level > MonologSettings::INFO)
		{
			$found
				? $this->checkFail('log file', "the probe arrived, though the level says Info is not written{$note}")
				: $this->checkSkip('log file', 'the probe was not written, as expected at ' . MonologSettings::levelName($level));
			return;
		}

		$found
			? $this->checkOk('log file', "the probe arrived in {$path}{$note}")
			: $this->checkFail('log file', "the probe did not arrive in {$path}{$note}");
	}

	/**
	 * The lines written since $offset that carry this run's id, split into the probe's own record
	 * and anything else mentioning it. Another record can quote the probe - a mail transport that
	 * logs what it sent, say - and must not be taken for it.
	 *
	 * @return array{0: int, 1: int} [the probe's own records, other lines mentioning the run]
	 */
	private function probeLines(string $path, int $offset, string $run): array
	{
		clearstatcache(true, $path);
		if (!is_file($path))
		{
			return [0, 0];
		}

		$own = 0;
		$others = 0;
		$handle = fopen($path, 'r');
		fseek($handle, $offset);
		while (($line = fgets($handle)) !== false)
		{
			if (strpos($line, $run) === false)
			{
				continue;
			}

			self::isProbeRecord($line, $run) ? $own++ : $others++;
		}
		fclose($handle);

		return [$own, $others];
	}

	/**
	 * The probe's own record, in either format the Monolog add-on writes: on the events channel,
	 * with the probe's message and this run's id in its context.
	 */
	public static function isProbeRecord(string $line, string $run): bool
	{
		$json = json_decode(trim($line), true);
		if (is_array($json))
		{
			return ($json['channel'] ?? '') === Emitter::CHANNEL
				&& ($json['message'] ?? '') === self::PROBE_MESSAGE
				&& ($json['context']['run'] ?? '') === $run;
		}

		$start = '/^\[[^\]]*\] ' . preg_quote(Emitter::CHANNEL, '/') . '\.INFO: ' . preg_quote(self::PROBE_MESSAGE, '/') . ' \{/';

		return (bool) preg_match($start, $line) && strpos($line, '"run":"' . $run . '"') !== false;
	}
}
