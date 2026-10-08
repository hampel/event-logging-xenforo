<?php namespace Tests\Unit;

use Hampel\EventLogging\Listener;
use Tests\TestCase;

/**
 * The declaration to the Admin API goes stale silently when an option is added or renamed, so it
 * is checked against this add-on's own options.
 */
class AdminApiRedactionTest extends TestCase
{
	private function declarations(): array
	{
		$declarations = [];
		Listener::adminApiRedaction($declarations);

		return $declarations;
	}

	public function test_it_declares_this_add_on_and_no_other()
	{
		$this->assertSame(['Hampel/EventLogging'], array_keys($this->declarations()));
	}

	public function test_every_declared_secret_is_an_option_this_add_on_has()
	{
		foreach ($this->declarations()['Hampel/EventLogging']['options'] ?? [] AS $path)
		{
			$optionId = explode('.', $path)[0];
			$this->assertFileExists(dirname(__DIR__, 2) . "/_output/options/{$optionId}.json", $path);
		}

		$this->addToAssertionCount(1);
	}

	/**
	 * A new option whose id reads like a credential fails here until someone decides whether it is
	 * one - declare it, or name it below as deliberately not.
	 */
	public function test_every_option_that_reads_like_a_credential_is_decided()
	{
		$declared = array_map(function (string $path)
		{
			return explode('.', $path)[0];
		}, $this->declarations()['Hampel/EventLogging']['options'] ?? []);

		$notSecrets = [];

		$options = glob(dirname(__DIR__, 2) . '/_output/options/*.json');
		$this->assertNotEmpty($options, 'the walk found the options directory');

		foreach ($options AS $file)
		{
			$optionId = basename($file, '.json');
			if ($optionId === '_metadata' || !preg_match('/key|token|secret|password|passwd|webhook|credential/i', $optionId))
			{
				continue;
			}

			$this->assertTrue(
				in_array($optionId, $declared, true) || in_array($optionId, $notSecrets, true),
				"{$optionId} reads like a credential: declare it, or list it as not a secret"
			);
		}
	}

	/**
	 * The listener answers an event this add-on does not define, so it must not depend on the
	 * add-on that does.
	 */
	public function test_the_add_on_does_not_require_the_admin_api()
	{
		$json = json_decode(file_get_contents(dirname(__DIR__, 2) . '/addon.json'), true);

		$this->assertArrayNotHasKey('Hampel/AdminApi', $json['require']);
	}
}
