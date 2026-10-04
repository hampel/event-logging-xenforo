<?php namespace Tests;

use Psr\Log\AbstractLogger;

/**
 * Keeps what it is given, in place of the `events` channel.
 */
class RecordingLogger extends AbstractLogger
{
	/** @var array[] each record: level, message, context */
	public $records = [];

	public function log($level, $message, array $context = []): void
	{
		$this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
	}

	/**
	 * @return array[] the records carrying this event name
	 */
	public function event(string $event): array
	{
		return array_values(array_filter($this->records, function (array $record) use ($event)
		{
			return ($record['context']['event'] ?? null) === $event;
		}));
	}
}
