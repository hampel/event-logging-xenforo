<?php namespace Hampel\EventLogging;

use Psr\Log\LogLevel;
use XF\App;

/**
 * Sends new rows of XenForo's server error log to the log, as `error.logged` at error.
 *
 * The row cannot be caught where it is written: XF\Error inserts it with a raw query, so no entity
 * event fires, and the `error` container key is a plain `new Error()`, so there is no class to
 * extend. This reads the table from the last error_id it sent, on a cron, and so runs up to one
 * cron interval behind.
 */
class ErrorLogMirror
{
	public const CACHE_KEY = 'errorId';

	/** The date of the newest row sent - what decides "new" once ids no longer can */
	public const DATE_KEY = 'errorDate';

	/** Rows read per run; a longer backlog is continued on the next */
	public const BATCH = 2000;

	/** Characters of the error message carried in a record */
	public const MESSAGE_LENGTH = 1000;

	/**
	 * Rows this add-on or the Monolog add-on logged about its own failure to log. Mirrored at
	 * error they would be handled, fail the same way, and be logged again - one round per run,
	 * indefinitely.
	 */
	public const SKIP_PREFIXES = ['Monolog:', Emitter::ERROR_PREFIX];

	/** @var App */
	protected $app;

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	/** @var bool|null whether xf_error_log exists, once asked */
	private static $tableExists;

	/**
	 * Whether this forum has a server error log table to read. Every XenForo this add-on supports
	 * has one; the check is so that a forum without it does nothing rather than failing on every
	 * cron run.
	 */
	public static function isSupported(): bool
	{
		if (self::$tableExists === null)
		{
			try
			{
				self::$tableExists = \XF::db()->getSchemaManager()->tableExists('xf_error_log');
			}
			catch (\Throwable $e)
			{
				return false;
			}
		}

		return self::$tableExists;
	}

	/**
	 * @return int the number of records emitted
	 */
	public function run(): int
	{
		$cache = $this->app->simpleCache()->getSet('Hampel/EventLogging');

		if (!self::isSupported() || !Emitter::enabled('errors'))
		{
			// forget the position, so turning the group on later starts from that moment rather
			// than sending everything logged while it was off
			foreach ([self::CACHE_KEY, self::DATE_KEY] AS $key)
			{
				if ($cache->keyExists($key))
				{
					$cache->deleteValue($key);
				}
			}

			return 0;
		}

		$db = $this->app->db();
		$max = (int) $db->fetchOne('SELECT MAX(error_id) FROM xf_error_log');

		if (!$cache->keyExists(self::CACHE_KEY))
		{
			$cache->setValue(self::CACHE_KEY, $max);
			$cache->setValue(self::DATE_KEY, $this->newestDate($max));

			return 0;
		}

		$last = (int) $cache->getValue(self::CACHE_KEY);
		$lastDate = $cache->keyExists(self::DATE_KEY) ? (int) $cache->getValue(self::DATE_KEY) : null;

		if ($last > $max)
		{
			// The last row sent is gone. Either the newest rows were deleted - an administrator
			// clearing the log, say - and what is left was all sent before, or the table was
			// emptied and its counter reset, so what is left is all new. Ids cannot tell those
			// apart and dates can: send only what is newer than the last row sent.
			if ($lastDate === null)
			{
				// a position kept before dates were: resume from the end rather than replay
				$cache->setValue(self::CACHE_KEY, $max);
				$cache->setValue(self::DATE_KEY, $this->newestDate($max));

				return 0;
			}

			$where = 'exception_date > ? AND error_id <= ?';
			$params = [$lastDate, $max];
		}
		else if ($last == $max)
		{
			return 0;
		}
		else
		{
			$where = 'error_id > ? AND error_id <= ?';
			$params = [$last, $max];
		}

		// not trace_string, which is long, nor request_state, which holds the request's whole
		// _GET and _POST
		$rows = $db->fetchAll($db->limit("
			SELECT error_id, exception_date, user_id, exception_type, filename, line,
				LEFT(message, " . self::MESSAGE_LENGTH . ") AS message
			FROM xf_error_log
			WHERE {$where}
			ORDER BY error_id
		", self::BATCH), $params);

		$emitted = 0;
		foreach ($this->collapse($rows) AS $error)
		{
			Emitter::emit('errors', 'error.logged', 'Server error logged', $error, LogLevel::ERROR);
			$emitted++;
		}

		$cache->setValue(self::CACHE_KEY, $rows ? (int) end($rows)['error_id'] : $max);

		$newest = $rows ? max(array_map('intval', array_column($rows, 'exception_date'))) : null;
		if ($lastDate === null)
		{
			$lastDate = $this->newestDate($last);
		}
		$cache->setValue(self::DATE_KEY, max($lastDate, (int) $newest));

		return $emitted;
	}

	/**
	 * The date of the newest row at or below $id, or 0 for an empty table.
	 */
	protected function newestDate(int $id): int
	{
		return (int) $this->app->db()->fetchOne(
			'SELECT MAX(exception_date) FROM xf_error_log WHERE error_id <= ?', $id
		);
	}

	/**
	 * One record per distinct error - type, file, line and message - with a count. A broken
	 * template logs the same exception on every page view; that is one thing to fix, not a thousand.
	 *
	 * @return array[] each record's context
	 */
	protected function collapse(array $rows): array
	{
		$errors = [];
		foreach ($rows AS $row)
		{
			if ($this->isOwnFailure((string) $row['message']))
			{
				continue;
			}

			// the message is part of what makes an error the same one: everything \XF::logError()
			// writes shares a type, file and line, because XenForo builds the exception itself
			$key = $row['exception_type'] . '|' . $row['filename'] . '|' . $row['line'] . '|' . $row['message'];
			if (isset($errors[$key]))
			{
				$errors[$key]['count']++;
				$errors[$key]['last_error_id'] = (int) $row['error_id'];
				continue;
			}

			$errors[$key] = [
				'fingerprint' => self::fingerprint($row),
				'error_id' => (int) $row['error_id'],
				'last_error_id' => (int) $row['error_id'],
				'count' => 1,
				'exception_type' => $row['exception_type'],
				'error_message' => $row['message'],
				'filename' => $row['filename'],
				'line' => (int) $row['line'],
				'user_id' => (int) $row['user_id'],
				'error_date' => gmdate('c', (int) $row['exception_date']),
			];
		}

		return array_values($errors);
	}

	/**
	 * What makes this error the same one the next time it is logged: the identity repeats are
	 * collapsed on, with the message hashed to keep it short. The Monolog add-on's email and Slack
	 * outputs skip a record whose fingerprint they sent recently - without one they compare the
	 * message alone, which is the same for every error here, so a second, different error would
	 * be skipped as a repeat of the first.
	 *
	 * Never the error_id: an id that changes on every row makes every row new.
	 */
	public static function fingerprint(array $row): string
	{
		return $row['exception_type'] . ':' . $row['filename'] . ':' . $row['line']
			. ':' . substr(md5((string) $row['message']), 0, 12);
	}

	protected function isOwnFailure(string $message): bool
	{
		foreach (self::SKIP_PREFIXES AS $prefix)
		{
			if (strpos($message, $prefix) === 0)
			{
				return true;
			}
		}

		return false;
	}
}
