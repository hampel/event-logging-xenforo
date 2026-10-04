<?php namespace Hampel\MonologEvents;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * The one way an event leaves this add-on: a record on the `events` channel of the Monolog Logging
 * Service add-on, with a stable `event` name in its context.
 *
 * Every event belongs to a group, and a group whose option is off emits nothing - so a forum that
 * installs this add-on and changes nothing records nothing.
 *
 * emit() never throws. It is called from inside a moderator's action, a login and a payment
 * callback, and none of those may fail because a log file could not be written.
 */
class Emitter
{
	public const CHANNEL = 'events';

	/** Prefix on anything this add-on writes to the server error log - the error log mirror skips it */
	public const ERROR_PREFIX = 'MonologEvents:';

	/**
	 * Each group, its option, and the events it emits. The event names are the public API: once
	 * released, a name is not changed. The README carries the same list.
	 */
	public const GROUPS = [
		'moderation' => [
			'option' => 'monologEventsModeration',
			'events' => ['moderator.action', 'user.banned', 'user.ban_updated', 'user.ban_lifted'],
		],
		'admin' => [
			'option' => 'monologEventsAdmin',
			'events' => ['admin.action'],
		],
		'spam' => [
			'option' => 'monologEventsSpam',
			'events' => ['spam.triggered'],
		],
		'auth' => [
			'option' => 'monologEventsAuth',
			'events' => ['login.failed'],
		],
		'errors' => [
			'option' => 'monologEventsErrors',
			'events' => ['error.logged'],
		],
		'mail' => [
			'option' => 'monologEventsMail',
			'events' => ['mail.sent', 'mail.failed'],
		],
		'payments' => [
			'option' => 'monologEventsPayments',
			'events' => ['payment.callback'],
		],
		'api' => [
			'option' => 'monologEventsApi',
			'events' => ['api.request'],
		],
	];

	/** @var LoggerInterface|null */
	private static $logger;

	/** @var bool whether a failed emit has already been reported in this request */
	private static $reported = false;

	public static function enabled(string $group): bool
	{
		$option = self::GROUPS[$group]['option'] ?? null;

		return $option !== null && (bool) \XF::options()->offsetGet($option);
	}

	/**
	 * @param string $group   a key of GROUPS; nothing is emitted unless its option is on
	 * @param string $event   noun.verb, lowercase - what a query or an alert matches on
	 * @param string $message a fixed string, for a person reading one line
	 * @param array  $context identifiers, never content - see the README's list of what is never logged
	 */
	public static function emit(string $group, string $event, string $message, array $context = [], string $level = LogLevel::INFO): void
	{
		try
		{
			if (!self::enabled($group))
			{
				return;
			}

			self::logger()->log($level, $message, ['event' => $event] + $context);
		}
		catch (\Throwable $e)
		{
			self::reportFailure($e);
		}
	}

	public static function logger(): LoggerInterface
	{
		if (self::$logger === null)
		{
			$container = \XF::app()->container();

			// required by addon.json, but an add-on can be disabled after ours is installed
			self::$logger = $container->offsetExists('monolog') && method_exists($container['monolog'], 'channel')
				? $container['monolog']->channel(self::CHANNEL)
				: new NullLogger();
		}

		return self::$logger;
	}

	/**
	 * Replaces the logger events are written to; null restores the `events` channel.
	 */
	public static function setLogger(?LoggerInterface $logger = null): void
	{
		self::$logger = $logger;
		self::$reported = false;
	}

	/**
	 * Once per request: a log file that cannot be written fails every emit, and a busy request
	 * would otherwise fill the server error log with the same line.
	 */
	private static function reportFailure(\Throwable $e): void
	{
		if (self::$reported)
		{
			return;
		}
		self::$reported = true;

		try
		{
			\XF::logException($e, false, self::ERROR_PREFIX . ' an event could not be logged - ');
		}
		catch (\Throwable $ignored)
		{
		}
	}
}
