<?php namespace Tests\Unit;

use Hampel\EventLogging\Check\HealthCheck;
use Hampel\EventLogging\ErrorLogMirror;
use Hampel\EventLogging\Listener;
use Tests\TestCase;

/**
 * The checks declared to the Admin API go stale silently: a check added to HealthCheck and not
 * declared runs on the command line and never over the API, and a declared method that has gone
 * fails every API call. Nothing here needs the Admin API installed, so the contract it enforces is
 * asserted here rather than borrowed from it.
 */
class AdminApiValidateTest extends TestCase
{
	/** The one check that writes, and so must not be marked safe to run over the API */
	private const WRITES = ['probeRecord'];

	/**
	 * @return array<string, mixed>
	 */
	private function validators(): array
	{
		$validators = [];
		Listener::adminApiValidate($validators);

		return $validators;
	}

	/**
	 * @return string[] the public instance methods HealthCheck declares itself, constructor aside
	 */
	private function checkMethods(): array
	{
		$methods = array_filter(
			(new \ReflectionClass(HealthCheck::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
			function (\ReflectionMethod $m) { return $m->class === HealthCheck::class && !$m->isConstructor() && !$m->isStatic(); }
		);

		return array_column($methods, 'name');
	}

	public function test_it_declares_checks_for_this_add_on_and_no_other()
	{
		$validators = $this->validators();

		$this->assertSame(['Hampel/EventLogging'], array_keys($validators));
		$this->assertSame(HealthCheck::class, $validators['Hampel/EventLogging']['class']);
	}

	public function test_every_check_is_declared_and_every_declaration_is_a_check()
	{
		$declared = $this->validators()['Hampel/EventLogging']['checks'];

		$this->assertNotEmpty($this->checkMethods(), 'the walk found the checks');
		$this->assertEqualsCanonicalizing($this->checkMethods(), array_column($declared, 'method'));
	}

	/**
	 * The command is the other caller, and a check it does not render is one the API reports and
	 * nobody at a shell sees.
	 */
	public function test_the_validate_command_renders_every_check()
	{
		$source = (string) file_get_contents(dirname(__DIR__, 2) . '/Cli/Command/Validate.php');

		foreach ($this->checkMethods() AS $method)
		{
			$this->assertStringContainsString("[\$checks, '{$method}']", $source, "event-logging:validate does not render {$method}()");
		}
	}

	/**
	 * The Admin API refuses an id or a label outside these, and they are the only text it returns.
	 * A label is counted in bytes, as the endpoint counts it.
	 */
	public function test_every_declared_check_meets_the_admin_api_contract()
	{
		foreach ($this->validators()['Hampel/EventLogging']['checks'] AS $id => $check)
		{
			$this->assertMatchesRegularExpression('/^[a-z0-9_]{1,50}$/', (string) $id);
			$this->assertIsString($check['label']);
			$this->assertNotSame('', $check['label']);
			$this->assertLessThanOrEqual(100, strlen($check['label']), "label of {$id}");

			in_array($check['method'], self::WRITES, true)
				? $this->assertArrayNotHasKey('api', $check, "{$id} writes, so it must not run over the API")
				: $this->assertTrue($check['api'] ?? false, "{$id} only reads, and is not marked safe to run over the API");
		}
	}

	/**
	 * What the Admin API reads from a row is its outcome, so anything but a list of three strings
	 * with a known outcome would count wrongly there and render wrongly here. Runs each read-only
	 * check against this forum.
	 */
	public function test_every_read_only_check_returns_rows_of_outcome_label_and_detail()
	{
		$checks = new HealthCheck(\XF::app());
		$outcomes = [HealthCheck::OK, HealthCheck::WARN, HealthCheck::FAIL, HealthCheck::SKIP];

		foreach (array_diff($this->checkMethods(), self::WRITES) AS $method)
		{
			$rows = $checks->$method();

			$this->assertNotEmpty($rows, "{$method}() returned no rows");
			$this->assertTrue(array_is_list($rows), "{$method}() did not return a list");

			foreach ($rows AS $row)
			{
				$this->assertCount(3, $row, $method);
				$this->assertTrue(array_is_list($row), "{$method}() returned a keyed row");
				$this->assertContains($row[0], $outcomes, $method);
				$this->assertIsString($row[1], $method);
				$this->assertIsString($row[2], $method);
			}
		}
	}

	/**
	 * Being declared safe is a claim that the check writes nothing; this is the evidence. The probe
	 * is left out, because writing is its job.
	 */
	public function test_the_read_only_checks_write_nothing()
	{
		$db = \XF::db();
		$errorsBefore = (int) $db->fetchOne('SELECT MAX(error_id) FROM xf_error_log');
		$cacheBefore = $this->mirrorPosition();

		$checks = new HealthCheck(\XF::app());
		foreach (array_diff($this->checkMethods(), self::WRITES) AS $method)
		{
			$checks->$method();
		}

		$this->assertSame($errorsBefore, (int) $db->fetchOne('SELECT MAX(error_id) FROM xf_error_log'));
		$this->assertSame($cacheBefore, $this->mirrorPosition());
	}

	/**
	 * @return array<string, mixed> the two values the mirror keeps, the only state a check reads
	 */
	private function mirrorPosition(): array
	{
		$cache = \XF::app()->simpleCache()->getSet('Hampel/EventLogging');

		return [
			'id' => $cache->keyExists(ErrorLogMirror::CACHE_KEY) ? $cache->getValue(ErrorLogMirror::CACHE_KEY) : 'unset',
			'date' => $cache->keyExists(ErrorLogMirror::DATE_KEY) ? $cache->getValue(ErrorLogMirror::DATE_KEY) : 'unset',
		];
	}
}
