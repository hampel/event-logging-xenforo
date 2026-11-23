<?php namespace Tests\Unit;

use Hampel\EventLogging\Check\HealthCheck;
use Tests\TestCase;

/**
 * Telling the probe's own record from another record that quotes it. A mail transport that logs
 * what it sent writes the emailed copy of the probe, run id and all, into the same file.
 */
class ProbeRecordTest extends TestCase
{
	private const RUN = 'ab12cd34';

	public function test_the_json_record_is_the_probes_own()
	{
		$line = json_encode([
			'message' => 'Event logging probe',
			'context' => ['event' => 'eventlogging.validate', 'probe' => true, 'run' => self::RUN],
			'level' => 200,
			'level_name' => 'INFO',
			'channel' => 'events',
			'extra' => [],
		]) . "\n";

		$this->assertTrue(HealthCheck::isProbeRecord($line, self::RUN));
	}

	public function test_the_line_record_is_the_probes_own()
	{
		$line = '[2026-10-08 09:15:02] events.INFO: Event logging probe {"event":"eventlogging.validate","probe":true,"run":"'
			. self::RUN . '"} {"request_id":"67ea87d5"}' . "\n";

		$this->assertTrue(HealthCheck::isProbeRecord($line, self::RUN));
	}

	public function test_another_runs_record_is_not()
	{
		$line = json_encode(['message' => 'Event logging probe', 'channel' => 'events', 'context' => ['run' => 'ffffffff']]);

		$this->assertFalse(HealthCheck::isProbeRecord($line, self::RUN));
	}

	public function test_a_json_record_on_another_channel_quoting_the_probe_is_not()
	{
		$line = json_encode([
			'message' => 'Sent email',
			'channel' => 'sparkpost',
			'context' => ['body' => 'events.INFO: Event logging probe {"run":"' . self::RUN . '"}'],
		]);

		$this->assertFalse(HealthCheck::isProbeRecord($line, self::RUN));
	}

	public function test_a_line_record_on_another_channel_quoting_the_probe_is_not()
	{
		$line = '[2026-10-08 09:15:03] sparkpost.DEBUG: Sent email {"body":"[2026-10-08 09:15:02] events.INFO: '
			. 'Event logging probe {\"run\":\"' . self::RUN . '\"}"} []';

		$this->assertFalse(HealthCheck::isProbeRecord($line, self::RUN));
	}

	/**
	 * A log file Monolog can append to and the command cannot read - write-only, or owned by the
	 * web server's user - is reported as unreadable, not a TypeError from fseek().
	 */
	public function test_a_log_file_that_cannot_be_read_is_reported_as_such()
	{
		$path = tempnam(sys_get_temp_dir(), 'eventlogging');
		file_put_contents($path, '{"message":"Event logging probe","run":"' . self::RUN . '"}' . "\n");
		chmod($path, 0200);

		try
		{
			if (is_readable($path))
			{
				$this->markTestSkipped('this process can read a write-only file, so the case cannot be made');
			}

			$probeLines = new \ReflectionMethod(HealthCheck::class, 'probeLines');
			$this->assertNull($probeLines->invoke(new HealthCheck(\XF::app()), $path, 0, self::RUN));
		}
		finally
		{
			chmod($path, 0600);
			unlink($path);
		}
	}
}
