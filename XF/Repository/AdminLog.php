<?php namespace Hampel\EventLogging\XF\Repository;

use Hampel\EventLogging\Emitter;

/**
 * XenForo writes the admin log with a raw insert, so no entity event fires for it and a class
 * extension is the only place to see one. Registered against the XenForo 2.2 class name, which
 * 2.3 resolves to AdminLogRepository.
 */
class AdminLog extends XFCP_AdminLog
{
	public function logAdminRequest($userId, $url, array $data, $ipAddress)
	{
		$logId = parent::logAdminRequest($userId, $url, $data, $ipAddress);

		if ($logId && Emitter::enabled('admin'))
		{
			// never $data: it is the submitted form, which on a user edit includes a password
			Emitter::emit('admin', 'admin.action', 'Admin action', [
				'admin_log_id' => (int) $logId,
				'actor_user_id' => (int) $userId,
				'route' => self::route((string) $url),
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
