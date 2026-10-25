<?php namespace Hampel\EventLogging;

/**
 * What the Monolog Logging Service add-on will do with a record on the `events` channel.
 *
 * That add-on's contract is the PSR-3 logger its channel() returns, and nothing else; these read
 * its own classes, which are not part of that contract. So each answer is null when the class it
 * needs is missing or has changed shape, and a caller reports "unknown" rather than failing. Only
 * the two commands use this - emitting an event never does.
 */
class MonologSettings
{
	public const INFO = 200;

	private const LEVELS = [
		100 => 'Debug', 200 => 'Info', 250 => 'Notice', 300 => 'Warning',
		400 => 'Error', 500 => 'Critical', 550 => 'Alert', 600 => 'Emergency',
	];

	/**
	 * The lowest level the log file takes from the `events` channel - its own, if config.php sets
	 * one - or null if there is no log file or it cannot be told.
	 */
	public static function fileLevel(): ?int
	{
		return self::guard(function ()
		{
			if (\Hampel\Monolog\Option\LogFile::path() === null)
			{
				return null;
			}

			return (int) \Hampel\Monolog\Config::levelFor(
				'file',
				Emitter::CHANNEL,
				(int) \Hampel\Monolog\Option\FileMinimumLogLevel::get()
			);
		});
	}

	/**
	 * Whether config.php sets a level for the `events` channel alone.
	 */
	public static function hasChannelLevel(): bool
	{
		return (bool) self::guard(function ()
		{
			return isset(\Hampel\Monolog\Config::channelLevels('file')[Emitter::CHANNEL]);
		});
	}

	public static function filePath(): ?string
	{
		return self::guard(function ()
		{
			return \Hampel\Monolog\Option\LogFile::path();
		});
	}

	/**
	 * `line` or `json`, or null if it cannot be told.
	 */
	public static function format(): ?string
	{
		return self::guard(function ()
		{
			return (string) \Hampel\Monolog\Option\LogFormat::get();
		});
	}

	/**
	 * Whether config.php replaces the log file's formatter, so its layout is not one of the two
	 * this add-on can recognise a record in.
	 */
	public static function hasCustomFormatter(): bool
	{
		return (bool) self::guard(function ()
		{
			return \Hampel\Monolog\Config::get('file', 'formatter') !== null;
		});
	}

	public static function levelName(int $level): string
	{
		return self::LEVELS[$level] ?? (string) $level;
	}

	/**
	 * @return mixed what the read returned, or null if it threw
	 */
	private static function guard(callable $read)
	{
		try
		{
			return $read();
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}
