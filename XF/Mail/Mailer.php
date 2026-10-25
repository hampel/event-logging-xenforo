<?php namespace Hampel\EventLogging\XF\Mail;

use Hampel\EventLogging\Emitter;
use Psr\Log\LogLevel;

class Mailer extends XFCP_Mailer
{
	/**
	 * Untyped, with XenForo 2.2's two extra parameters: 2.2 sends a Swift message and 2.3 a Symfony
	 * one, and this signature is compatible with both parents.
	 *
	 * @param object $message \Swift_Mime_SimpleMessage on 2.2, \Symfony\Component\Mime\Email on 2.3
	 * @param mixed $transport
	 * @param array<string, mixed>|null $queueEntry 2.2 only
	 * @param bool $allowRetry 2.2 only
	 * @return mixed the parent's: a count on 2.2, a SentMessage, false or null on 2.3
	 */
	public function send($message, $transport = null, $queueEntry = null, $allowRetry = true)
	{
		// passed on as given: each parent declares its own message class, and this signature has to
		// accept both
		$sent = parent::send(...func_get_args()); // @phpstan-ignore argument.type

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
	 *
	 * @param mixed $message
	 * @return array{recipient_count: int, recipients: list<string>, subject: ?string}
	 */
	public static function describe($message): array
	{
		$recipients = [];
		$subject = null;

		try
		{
			$to = is_object($message) && method_exists($message, 'getTo') ? $message->getTo() : [];
			foreach ((array) $to AS $key => $value)
			{
				$recipients[] = $value instanceof \Symfony\Component\Mime\Address ? $value->getAddress() : (string) $key;
			}

			$subject = is_object($message) && method_exists($message, 'getSubject') ? $message->getSubject() : null;
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
