<?php namespace Tests\Unit;

use Hampel\EventLogging\XF\Mail\Mailer;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

/**
 * The two shapes of message Mailer::send() is handed: XenForo 2.2's Swift message, whose getTo()
 * is address => name, and 2.3's Symfony one, whose getTo() is a list of Address objects.
 */
class MailDescribeTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		// the class extends a proxy XenForo only creates when it resolves the extension
		$this->app()->extendClass('XF\Mail\Mailer');
	}

	private function message($to, $subject)
	{
		return new class($to, $subject)
		{
			private $to;
			private $subject;

			public function __construct($to, $subject)
			{
				$this->to = $to;
				$this->subject = $subject;
			}

			public function getTo()
			{
				return $this->to;
			}

			public function getSubject()
			{
				return $this->subject;
			}
		};
	}

	public function test_a_swift_message_is_described_by_its_address_keys()
	{
		$message = $this->message(['alice@example.com' => 'Alice', 'bob@example.com' => null], 'Hello');

		$this->assertSame([
			'recipient_count' => 2,
			'recipients' => ['alice@example.com', 'bob@example.com'],
			'subject' => 'Hello',
		], Mailer::describe($message));
	}

	public function test_a_symfony_message_is_described_by_its_address_objects()
	{
		$message = $this->message([new Address('alice@example.com', 'Alice'), new Address('bob@example.com')], 'Hello');

		$this->assertSame([
			'recipient_count' => 2,
			'recipients' => ['alice@example.com', 'bob@example.com'],
			'subject' => 'Hello',
		], Mailer::describe($message));
	}

	public function test_a_message_with_no_recipients_or_subject_is_still_described()
	{
		$this->assertSame(
			['recipient_count' => 0, 'recipients' => [], 'subject' => null],
			Mailer::describe($this->message(null, null))
		);
	}

	/**
	 * Describing a message must never be what stops it being sent.
	 */
	public function test_an_object_that_is_not_a_message_is_described_as_empty()
	{
		$this->assertSame(
			['recipient_count' => 0, 'recipients' => [], 'subject' => null],
			Mailer::describe(new \stdClass())
		);
	}
}
