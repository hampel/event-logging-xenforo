<?php namespace Hampel\EventLogging\XF\Mail;

use Hampel\EventLogging\Emitter;
use Psr\Log\LogLevel;

class Mailer extends XFCP_Mailer
{
	/**
	 * Untyped, with XenForo 2.2's two extra parameters: 2.2 sends a Swift message and 2.3 a Symfony
	 * one, and this signature is compatible with both parents.
	 */
	public function send($message, $transport = null, $queueEntry = null, $allowRetry = true)
	{
		$sent = parent::send(...func_get_args());

		if (Emitter::enabled('mail'))
		{
			$context = self::describe($message);

			// a failure is a warning: XenForo has already written the exception to the server
			// error log, and a queued email is tried again
			$sent
				? Emitter::emit('mail', 'mail.sent', 'Email sent', $context)
				: Emitter::emit('mail', 'mail.failed', 'Email failed', $context, LogLevel::WARNING);
		}

		return $sent;
	}

	/**
	 * Who an email is to and what it is called - what is needed to answer "did the forum send it?".
	 * Never the body: it is where a password reset or confirmation link is.
	 *
	 * Swift's getTo() is address => name; Symfony's is a list of Address objects.
	 */
	public static function describe($message): array
	{
		$recipients = [];
		$subject = null;

		try
		{
			$to = method_exists($message, 'getTo') ? $message->getTo() : [];
			foreach ((array) $to AS $key => $value)
			{
				$recipients[] = is_object($value) ? $value->getAddress() : (string) $key;
			}

			$subject = method_exists($message, 'getSubject') ? $message->getSubject() : null;
		}
		catch (\Throwable $e)
		{
		}

		return [
			'recipient_count' => count($recipients),
			'recipients' => $recipients,
			'subject' => $subject === null ? null : (string) $subject,
		];
	}
}
