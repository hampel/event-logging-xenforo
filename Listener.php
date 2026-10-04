<?php namespace Hampel\EventLogging;

use Psr\Log\LogLevel;
use XF\Mvc\Entity\Entity;

/**
 * The events XenForo writes through an entity, where an entity event fires. The admin log and the
 * spam trigger log are raw inserts and are caught by class extensions instead; the server error
 * log has neither and is read on a cron.
 *
 * Each record carries identifiers and nothing else - no titles, reasons, usernames or submitted
 * data. Whoever needs the rest follows the id into the forum.
 */
class Listener
{
	public static function moderatorLogPostSave(Entity $entity)
	{
		if (!$entity->isInsert() || !Emitter::enabled('moderation'))
		{
			return;
		}

		Emitter::emit('moderation', 'moderator.action', 'Moderator action', [
			'moderator_log_id' => (int) $entity->moderator_log_id,
			'actor_user_id' => (int) $entity->user_id,
			'content_type' => $entity->content_type,
			'content_id' => (int) $entity->content_id,
			'content_user_id' => (int) $entity->content_user_id,
			'discussion_content_type' => $entity->discussion_content_type,
			'discussion_content_id' => (int) $entity->discussion_content_id,
			'action' => $entity->action,
		]);
	}

	public static function loginAttemptPostSave(Entity $entity)
	{
		if (!$entity->isInsert() || !Emitter::enabled('auth'))
		{
			return;
		}

		// the name or address entered is not logged: it is personal data, and sometimes a password
		// typed into the wrong field. The member it matches, if any, is what a reader needs.
		$user = null;
		try
		{
			$user = \XF::repository('XF:User')->getUserByNameOrEmail($entity->login);
		}
		catch (\Throwable $e)
		{
		}

		Emitter::emit('auth', 'login.failed', 'Login failed', [
			'user_id' => $user ? (int) $user->user_id : 0,
			'ip' => self::ip($entity->ip_address),
		]);
	}

	public static function userBanPostSave(Entity $entity)
	{
		if (!Emitter::enabled('moderation'))
		{
			return;
		}

		$endDate = (int) $entity->end_date;

		Emitter::emit(
			'moderation',
			$entity->isInsert() ? 'user.banned' : 'user.ban_updated',
			$entity->isInsert() ? 'User banned' : 'User ban updated',
			[
				'user_id' => (int) $entity->user_id,
				'actor_user_id' => (int) $entity->ban_user_id,
				'permanent' => $endDate === 0,
				'end_date' => $endDate ? gmdate('c', $endDate) : null,
				'triggered' => (bool) $entity->triggered,
			]
		);
	}

	public static function userBanPostDelete(Entity $entity)
	{
		if (!Emitter::enabled('moderation'))
		{
			return;
		}

		$endDate = (int) $entity->end_date;

		Emitter::emit('moderation', 'user.ban_lifted', 'User ban lifted', [
			'user_id' => (int) $entity->user_id,
			'expired' => $endDate > 0 && $endDate <= \XF::$time,
		]);
	}

	public static function paymentProviderLogPostSave(Entity $entity)
	{
		if (!$entity->isInsert() || !Emitter::enabled('payments'))
		{
			return;
		}

		// log_details is the provider's whole callback and purchase_request_key identifies a
		// purchase to anyone holding it; neither is logged
		Emitter::emit('payments', 'payment.callback', 'Payment callback', [
			'provider_log_id' => (int) $entity->provider_log_id,
			'provider_id' => $entity->provider_id,
			'log_type' => $entity->log_type,
			'transaction_id' => $entity->transaction_id,
		], $entity->log_type === 'error' ? LogLevel::WARNING : LogLevel::INFO);
	}

	public static function appApiComplete(\XF\App $app, \XF\Http\Response &$response)
	{
		if (!Emitter::enabled('api'))
		{
			return;
		}

		try
		{
			$request = $app->request();

			// the route only: a query string can carry the API key itself
			$context = [
				'api_key_id' => (int) \XF::apiKey()->api_key_id,
				'user_id' => (int) \XF::visitor()->user_id,
				'method' => strtoupper($request->getRequestMethod()),
				'route' => $request->getRoutePath(),
				'status' => (int) $response->httpCode(),
			];
		}
		catch (\Throwable $e)
		{
			return;
		}

		Emitter::emit('api', 'api.request', 'API request', $context);
	}

	/**
	 * A packed IPv4 or IPv6 address as XenForo stores it, as text; null if it is neither.
	 */
	public static function ip($binary): ?string
	{
		if (!is_string($binary) || (strlen($binary) !== 4 && strlen($binary) !== 16))
		{
			return null;
		}

		$ip = @inet_ntop($binary);

		return $ip === false ? null : $ip;
	}
}
