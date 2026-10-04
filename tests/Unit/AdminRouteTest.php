<?php namespace Tests\Unit;

use Hampel\EventLogging\XF\Repository\AdminLog;
use Tests\TestCase;

/**
 * admin.action carries the route and none of the parameters after it, which is where a token is.
 */
class AdminRouteTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		// the class extends a proxy XenForo only creates when it resolves the extension
		$this->app()->extendClass('XF\Repository\AdminLog');
	}

	public function test_the_route_is_the_first_part_of_the_query_string()
	{
		$this->assertSame('users/1/save', AdminLog::route('/admin.php?users/1/save'));
		$this->assertSame('users/1/save', AdminLog::route('/forum/admin.php?users/1/save'));
	}

	public function test_parameters_after_the_route_are_dropped()
	{
		$this->assertSame('users/1/save', AdminLog::route('/admin.php?users/1/save&_xfToken=secret&a=b'));
	}

	public function test_a_request_with_no_route_has_an_empty_one()
	{
		$this->assertSame('', AdminLog::route('/admin.php'));
		$this->assertSame('', AdminLog::route('/admin.php?'));
		$this->assertSame('', AdminLog::route('/admin.php?a=b'), 'a parameter is not a route');
	}

	public function test_an_encoded_route_is_decoded_and_a_long_one_is_cut()
	{
		$this->assertSame('users/a b.1/edit', AdminLog::route('/admin.php?users/a%20b.1/edit'));
		$this->assertSame(150, strlen(AdminLog::route('/admin.php?' . str_repeat('x', 400))));
	}
}
