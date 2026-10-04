<?php namespace Tests\Unit;

use Hampel\EventLogging\ErrorLogMirror;
use Tests\TestCase;

/**
 * What makes a mirrored error the same one the next time it is logged.
 */
class FingerprintTest extends TestCase
{
	private function row(array $values = []): array
	{
		return $values + [
			'error_id' => 1,
			'exception_type' => 'ErrorException',
			'filename' => 'src/XF/Error.php',
			'line' => 77,
			'message' => 'Search index is unreachable',
		];
	}

	public function test_the_same_error_has_the_same_fingerprint_whatever_its_id()
	{
		$this->assertSame(
			ErrorLogMirror::fingerprint($this->row(['error_id' => 1])),
			ErrorLogMirror::fingerprint($this->row(['error_id' => 2]))
		);
	}

	/**
	 * Everything \XF::logError() writes shares a type, file and line, so the message has to count.
	 */
	public function test_each_part_of_the_identity_changes_the_fingerprint()
	{
		$base = ErrorLogMirror::fingerprint($this->row());

		foreach ([
			'exception_type' => 'TypeError',
			'filename' => 'src/XF/App.php',
			'line' => 78,
			'message' => 'Payment provider rejected the callback',
		] AS $column => $value)
		{
			$this->assertNotSame($base, ErrorLogMirror::fingerprint($this->row([$column => $value])), $column);
		}
	}

	public function test_the_message_is_hashed_so_a_long_one_stays_short()
	{
		$print = ErrorLogMirror::fingerprint($this->row(['message' => str_repeat('x', 1000)]));

		$this->assertStringStartsWith('ErrorException:src/XF/Error.php:77:', $print);
		$this->assertLessThan(60, strlen($print));
		$this->assertStringNotContainsString('xxx', $print);
	}
}
