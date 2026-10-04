<?php namespace Hampel\MonologEvents\XF\Repository;

use Hampel\MonologEvents\Emitter;

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
	 * The admin route from a request URI - `users/1/save` from `/admin.php?users/1/save&x=y` -
	 * without the parameters that follow it.
	 */
	public static function route(string $url): string
	{
		$query = strpos($url, '?') === false ? '' : substr($url, strpos($url, '?') + 1);
		$route = strtok($query, '&');

		if ($route === false || strpos($route, '=') !== false)
		{
			return '';
		}

		return substr(rawurldecode($route), 0, 150);
	}
}
