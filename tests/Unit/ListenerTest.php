<?php namespace Tests\Unit;

use Hampel\EventLogging\Listener;
use Tests\TestCase;

/**
 * The two values the listeners derive rather than copy.
 */
class ListenerTest extends TestCase
{
	public function test_a_reason_is_taken_from_the_action_parameters()
	{
		$this->assertSame('spam', Listener::reason(['reason' => 'spam', 'old_title' => 'Before']));
	}

	public function test_an_action_with_no_reason_has_a_null_reason()
	{
		$this->assertNull(Listener::reason([]));
		$this->assertNull(Listener::reason(['reason' => '']));
		$this->assertNull(Listener::reason(['reason' => ['not', 'text']]));
		$this->assertNull(Listener::reason(''), 'XenForo stores no parameters as an empty string');
		$this->assertNull(Listener::reason(null));
	}

	public function test_a_stored_ip_address_is_converted_to_text()
	{
		$this->assertSame('203.0.113.9', Listener::ip(inet_pton('203.0.113.9')));
		$this->assertSame('2001:db8::1', Listener::ip(inet_pton('2001:db8::1')));
	}

	/**
	 * XenForo stores an empty string where it had no address, and inet_ntop() warns on anything
	 * that is not four or sixteen bytes.
	 */
	public function test_anything_that_is_not_a_stored_ip_address_is_null()
	{
		$this->assertNull(Listener::ip(''));
		$this->assertNull(Listener::ip(null));
		$this->assertNull(Listener::ip('203.0.113.9'), 'text, not the packed form');
		$this->assertNull(Listener::ip('abc'));
	}
}
