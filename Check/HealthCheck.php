<?php namespace Hampel\EventLogging\Check;

use Hampel\EventLogging\Emitter;
use Hampel\EventLogging\ErrorLogMirror;
use Hampel\EventLogging\MonologSettings;
use Psr\Log\NullLogger;
use XF\App;

/**
 * What event-logging:validate reports, kept apart from the command so that it is one caller of
 * these checks and not the only one.
 *
 * **Each method returns rows of `[outcome, label, detail]`**, where the outcome is ok, warn, fail
 * or skip - the lines the command prints. The other caller is the Admin API, which runs the checks
 * `Listener::adminApiValidate()` declares and returns only the outcomes, never a label or a
 * detail. One class is what stops the command and the endpoint drifting apart.
 *
 * **Every check but probeRecord() only reads**, which is what lets them be declared safe to run
 * over the API. probeRecord() writes a record to the log and is declared without that flag, so the
 * API lists it and does not run it; the command runs it unless --unattended is given. A check that
 * throws is the caller's to report: the command logs it and prints a failure, and the Admin API
 * counts it as one.
 *
 * @phpstan-type Row array{0: string, 1: string, 2: string}
 */
class HealthCheck
{
	public const OK = 'ok';
	public const WARN = 'warn';
	public const FAIL = 'fail';
	public const SKIP = 'skip';

	private const PROBE_MESSAGE = 'Event logging probe';

	/** @var App */
	protected $app;

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	/**
	 * @return list<Row>
	 */
	public function monolog(): array
	{
		return [Emitter::logger() instanceof NullLogger
			? [self::FAIL, 'Monolog add-on', 'not installed, disabled, or older than 5.0.1 - no event is logged']
			: [self::OK, 'Monolog add-on', 'provides the ' . Emitter::CHANNEL . ' channel']];
	}

	/**
	 * @return list<Row>
	 */
	public function groups(): array
	{
		$on = array_values(array_filter(array_keys(Emitter::GROUPS), [Emitter::class, 'enabled']));

		return [$on
			? [self::OK, 'event groups', count($on) . ' of ' . count(Emitter::GROUPS) . ' on: ' . implode(', ', $on)]
			: [self::WARN, 'event groups', 'none is on - nothing is emitted until one is, under Options > Monolog']];
	}

	/**
	 * Event records are Info, and the log file's default level is Warning - so until the `events`
	 * channel is raised, an event that is emitted is written nowhere. A warning, not a failure:
	 * the error log mirror emits at Error and still arrives.
	 *
	 * @return list<Row>
	 */
	public function logFile(): array
	{
		if (Emitter::logger() instanceof NullLogger)
		{
			// a disabled add-on's options are still readable, and describe a file nothing writes to
			return [
				[self::SKIP, 'level', 'the Monolog add-on is not available'],
				[self::SKIP, 'format', 'the Monolog add-on is not available'],
			];
		}

		$rows = [];

		$level = MonologSettings::fileLevel();
		if ($level === null)
		{
			$rows[] = MonologSettings::filePath() === null
				? [self::SKIP, 'level', 'file logging is off, or its settings could not be read']
				: [self::WARN, 'level', 'could not be read from the Monolog add-on'];
		}
		else if ($level > MonologSettings::INFO)
		{
			$rows[] = [self::WARN, 'level', MonologSettings::levelName($level) . ' - events are Info and are not written. Set '
				. "\$config['monolog']['file']['channels']['" . Emitter::CHANNEL . "'] = 'info'; in config.php"];
		}
		else
		{
			$rows[] = [self::OK, 'level', MonologSettings::levelName($level) . (MonologSettings::hasChannelLevel()
				? ', for the ' . Emitter::CHANNEL . ' channel alone' : ', for every channel')];
		}

		$format = MonologSettings::format();
		if ($format === null)
		{
			$rows[] = [self::SKIP, 'format', 'could not be read from the Monolog add-on'];
		}
		else if ($format !== 'json')
		{
			$rows[] = [self::WARN, 'format', "{$format} - a log collector can only read an event's fields from the JSON format"];
		}
		else
		{
			$rows[] = [self::OK, 'format', 'json'];
		}

		return $rows;
	}

	/**
	 * @return list<Row>
	 */
	public function errorLog(): array
	{
		if (!ErrorLogMirror::isSupported())
		{
			return [[self::SKIP, 'error log mirror', 'not used - this forum has no server error log table']];
		}

		if (!Emitter::enabled('errors'))
		{
			return [[self::SKIP, 'error log mirror', 'the errors group is off']];
		}

		$cron = $this->app->em()->find('XF:CronEntry', 'eventLoggingErrorLog');
		if (!$cron || !$cron->active)
		{
			return [[self::FAIL, 'cron entry', 'eventLoggingErrorLog is missing or disabled - no server error is sent']];
		}
		$rows = [[self::OK, 'cron entry', 'active']];

		$mirror = new ErrorLogMirror($this->app);
		$position = $mirror->position();
		if ($position['last'] === null)
		{
			$rows[] = [self::OK, 'position', 'not started - the next cron run begins from the newest error'];

			return $rows;
		}

		$waiting = $mirror->waiting();
		$at = "error #{$position['last']}";
		if ($position['pastEnd'])
		{
			// rows were deleted from the log, or it was emptied: not a fault, and the mirror
			// sends only what is newer than the last error it sent
			$at .= ", past the end of the log (#{$position['max']})";
		}

		$rows[] = $waiting > ErrorLogMirror::BATCH
			? [self::WARN, 'position', "{$at} - {$waiting} waiting, more than one run sends; is cron running?"]
			: [self::OK, 'position', "{$at} - {$waiting} waiting for the next run"];

		return $rows;
	}

	/**
	 * One record straight to the channel, whichever groups are on, found again in the log file by
	 * its run id. `probe: true` is there for an alert to exclude.
	 *
	 * **This one writes**, so it is declared to the Admin API without `api`, and the command skips
	 * it under --unattended.
	 *
	 * @return list<Row>
	 */
	public function probeRecord(): array
	{
		$logger = Emitter::logger();
		if ($logger instanceof NullLogger)
		{
			return [[self::SKIP, 'probe record', 'no logger to write to']];
		}

		$run = bin2hex(random_bytes(4));
		$path = MonologSettings::filePath();
		$isFile = $path !== null && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $path);
		$offset = $isFile && is_file($path) ? (int) filesize($path) : 0;
		$db = $this->app->db();
		$errorsBefore = (int) $db->fetchOne('SELECT MAX(error_id) FROM xf_error_log');

		$logger->info(self::PROBE_MESSAGE, ['event' => 'eventlogging.validate', 'probe' => true, 'run' => $run]);
		$rows = [[self::OK, 'probe record', "written to the " . Emitter::CHANNEL . " channel at Info, run {$run}"]];

		$rows[] = $this->probeArrived($isFile ? $path : null, $offset, $run);

		// a handler that fails quietly - an unwritable file, a refused post - reports here and
		// nowhere else
		/** @var list<array{message: string}> $errors */
		$errors = $db->fetchAll('SELECT message FROM xf_error_log WHERE error_id > ? ORDER BY error_id', $errorsBefore);
		$rows[] = $errors
			? [self::FAIL, 'server error log', count($errors) . ' new: ' . strtok($errors[0]['message'], "\n")]
			: [self::OK, 'server error log', 'nothing logged while the probe was written'];

		return $rows;
	}

	/**
	 * @return Row
	 */
	private function probeArrived(?string $path, int $offset, string $run): array
	{
		$level = MonologSettings::fileLevel();
		if ($path === null || $level === null)
		{
			return [self::SKIP, 'log file', 'no log file to look in'];
		}

		$lines = $this->probeLines($path, $offset, $run);
		if ($lines === null)
		{
			// Monolog can append to a file this user cannot read: one owned by the web server's user
			return [self::FAIL, 'log file', "{$path} cannot be read, so the probe cannot be looked for"];
		}

		[$own, $others] = $lines;
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
			return $found
				? [self::FAIL, 'log file', "the probe arrived, though the level says Info is not written{$note}"]
				: [self::SKIP, 'log file', 'the probe was not written, as expected at ' . MonologSettings::levelName($level)];
		}

		return $found
			? [self::OK, 'log file', "the probe arrived in {$path}{$note}"]
			: [self::FAIL, 'log file', "the probe did not arrive in {$path}{$note}"];
	}

	/**
	 * The lines written since $offset that carry this run's id, split into the probe's own record
	 * and anything else mentioning it. Another record can quote the probe - a mail transport that
	 * logs what it sent, say - and must not be taken for it.
	 *
	 * @return array{0: int, 1: int}|null [the probe's own records, other lines mentioning the run],
	 *         or null when the file cannot be read
	 */
	private function probeLines(string $path, int $offset, string $run): ?array
	{
		clearstatcache(true, $path);
		if (!is_file($path))
		{
			return [0, 0];
		}

		$own = 0;
		$others = 0;
		$handle = @fopen($path, 'r');
		if ($handle === false)
		{
			return null;
		}

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
	 *
	 * Static, so it is not a check: the declaration lists every public instance method.
	 */
	public static function isProbeRecord(string $line, string $run): bool
	{
		$json = json_decode(trim($line), true);
		if (is_array($json))
		{
			return ($json['channel'] ?? '') === Emitter::CHANNEL
				&& ($json['message'] ?? '') === self::PROBE_MESSAGE
				&& is_array($json['context'] ?? null)
				&& ($json['context']['run'] ?? '') === $run;
		}

		$start = '/^\[[^\]]*\] ' . preg_quote(Emitter::CHANNEL, '/') . '\.INFO: ' . preg_quote(self::PROBE_MESSAGE, '/') . ' \{/';

		return (bool) preg_match($start, $line) && strpos($line, '"run":"' . $run . '"') !== false;
	}
}
