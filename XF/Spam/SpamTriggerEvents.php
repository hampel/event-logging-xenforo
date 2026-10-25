<?php namespace Hampel\EventLogging\XF\Spam;

use Hampel\EventLogging\Emitter;

/**
 * XenForo writes the spam trigger log with a raw insert in the checkers' shared parent, which is
 * abstract and so cannot be extended itself. Both concrete checkers use this.
 */
trait SpamTriggerEvents
{
	/**
	 * @param string $contentType
	 * @param int|string|null $contentId
	 * @return int|string|bool the parent's: the new row's id, true, or false when nothing was logged
	 */
	public function logSpamTrigger($contentType, $contentId)
	{
		$logged = parent::logSpamTrigger($contentType, $contentId);

		if ($logged !== false && Emitter::enabled('spam'))
		{
			try
			{
				// as the parent chooses it: the most recent decision that was not `allowed`
				$failures = array_filter(array_reverse($this->decisions), function ($decision)
				{
					return $decision != 'allowed';
				});

				$userId = $contentType == 'user' ? (int) $contentId : (int) \XF::visitor()->user_id;

				// the details and request state XenForo stores hold the submitted content; not logged
				Emitter::emit('spam', 'spam.triggered', 'Spam trigger', [
					'content_type' => $contentType,
					'content_id' => $contentId ? (int) $contentId : null,
					'user_id' => $userId,
					'result' => reset($failures) ?: null,
					'ip' => $this->app()->request()->getIp() ?: null,
				]);
			}
			catch (\Throwable $e)
			{
			}
		}

		return $logged;
	}
}
