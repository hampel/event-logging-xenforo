<?php namespace Hampel\MonologEvents;

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

	/** Rows read per run; a longer backlog is continued on the next */
	public const BATCH = 2000;

	/** Characters of the error message carried in a record */
	public const MESSAGE_LENGTH = 1000;

	/**
	 * Rows this add-on or the Monolog add-on logged about its own failure to log. Mirrored at
	 * error they would be handled, fail the same way, and be logged again - one round per run,
	 * indefinitely.
	 */
	public const SKIP_PREFIXES = ['Monolog:', Emitter::ERROR_PREFIX, "\$config['monolog']"];

	/** @var App */
	protected $app;

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	/**
	 * XenForo 2.4 writes its errors through Monolog itself and has no xf_error_log to read.
	 */
	public static function isSupported(): bool
	{
		return \XF::$versionId < 2040000;
	}

	/**
	 * @return int the number of records emitted
	 */
	public function run(): int
	{
		$cache = $this->app->simpleCache()->getSet('Hampel/MonologEvents');

		if (!self::isSupported() || !Emitter::enabled('errors'))
		{
			// forget the position, so turning the group on later starts from that moment rather
			// than sending everything logged while it was off
			if ($cache->keyExists(self::CACHE_KEY))
			{
				$cache->deleteValue(self::CACHE_KEY);
			}

			return 0;
		}

		$db = $this->app->db();
		$max = (int) $db->fetchOne('SELECT MAX(error_id) FROM xf_error_log');

		if (!$cache->keyExists(self::CACHE_KEY))
		{
			$cache->setValue(self::CACHE_KEY, $max);

			return 0;
		}

		$last = (int) $cache->getValue(self::CACHE_KEY);
		if ($last > $max)
		{
			// the table was emptied and its counter reset
			$last = 0;
		}
		if ($last == $max)
		{
			return 0;
		}

		// not trace_string, which is long, nor request_state, which holds the request's whole
		// _GET and _POST
		$rows = $db->fetchAll($db->limit('
			SELECT error_id, exception_date, user_id, exception_type, filename, line,
				LEFT(message, ' . self::MESSAGE_LENGTH . ') AS message
			FROM xf_error_log
			WHERE error_id > ? AND error_id <= ?
			ORDER BY error_id
		', self::BATCH), [$last, $max]);

		$emitted = 0;
		foreach ($this->collapse($rows) AS $error)
		{
			Emitter::emit('errors', 'error.logged', 'Server error logged', $error, LogLevel::ERROR);
			$emitted++;
		}

		$cache->setValue(self::CACHE_KEY, $rows ? (int) end($rows)['error_id'] : $max);

		return $emitted;
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
