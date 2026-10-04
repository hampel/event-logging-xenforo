<?php namespace Hampel\MonologEvents\XF\Mail;

use Hampel\MonologEvents\Emitter;
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
			// a count, never an address, the subject or the body
			$recipients = null;
			try
			{
				$to = method_exists($message, 'getTo') ? $message->getTo() : null;
				$recipients = is_array($to) ? count($to) : null;
			}
			catch (\Throwable $e)
			{
			}

			// a failure is a warning: XenForo has already written the exception to the server
			// error log, and a queued email is tried again
			$sent
				? Emitter::emit('mail', 'mail.sent', 'Email sent', ['recipient_count' => $recipients])
				: Emitter::emit('mail', 'mail.failed', 'Email failed', ['recipient_count' => $recipients], LogLevel::WARNING);
		}

		return $sent;
	}
}
