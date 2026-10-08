<?php namespace Hampel\EventLogging\XF\Repository;

use Hampel\EventLogging\Emitter;

/**
 * XenForo writes the admin log with a raw insert, so no entity event fires for it and a class
 * extension is the only place to see one. Registered against the XenForo 2.2 class name, which
 * 2.3 resolves to AdminLogRepository.
 */
class AdminLog extends XFCP_AdminLog
{
	/** The request an open admin control panel sends on a timer, to keep its session alive */
	public const KEEP_ALIVE_ROUTE = 'login/keep-alive';

	public const SKIP_KEEP_ALIVE_OPTION = 'eventLoggingAdminSkipKeepAlive';

	public function logAdminRequest($userId, $url, array $data, $ipAddress)
	{
		$logId = parent::logAdminRequest($userId, $url, $data, $ipAddress);

		if ($logId && Emitter::enabled('admin'))
		{
			$route = self::route((string) $url);

			// XenForo logs the keep-alive like any other request, so by default it is mirrored
			// like any other; the option leaves it out for an owner who wants only actions
			if ($route === self::KEEP_ALIVE_ROUTE && \XF::options()->offsetGet(self::SKIP_KEEP_ALIVE_OPTION))
			{
				return $logId;
			}

			// never $data: it is the submitted form, which on a user edit includes a password
			Emitter::emit('admin', 'admin.action', 'Admin action', [
				'admin_log_id' => (int) $logId,
				'actor_user_id' => (int) $userId,
				'route' => $route,
			]);
		}

		return $logId;
	}

	/**
	 * The admin route, without any parameters that follow it.
	 *
	 * XenForo's admin controller passes the request's route path - `users/1/save` - and not a URI,
	 * whatever the parameter is called. A URI is accepted too, since anything can call the
	 * repository: `/admin.php?users/1/save&x=y` gives the same route.
	 */
	public static function route(string $url): string
	{
		$path = strpos($url, '?') === false ? $url : substr($url, strpos($url, '?') + 1);
		$route = strtok(ltrim($path, '/'), '&');

		if ($route === false || strpos($route, '=') !== false)
		{
			return '';
		}

		return substr(rawurldecode($route), 0, 150);
	}
}
