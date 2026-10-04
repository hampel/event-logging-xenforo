<?php namespace Tests;

use Hampel\MonologEvents\Emitter;
use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	/**
	 * @var string $rootDir path to the XenForo root directory, relative to the addon path
	 *
	 * No trailing slash!
	 */
	protected $rootDir = '../../../..';

	/**
	 * @var array $addonsToLoad this add-on and the one it requires, so that no other add-on's
	 * listeners or class extensions can affect the run
	 */
	protected $addonsToLoad = ['Hampel/Monolog', 'Hampel/MonologEvents'];

	/** @var RecordingLogger what the add-on emitted during this test */
	protected $events;

	/**
	 * Every test starts with every event group off - the add-on's own default - whatever the
	 * install's options page holds, and with a recording logger in place of the `events` channel
	 * so nothing reaches a real log file.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$off = [];
		foreach (Emitter::GROUPS AS $definition)
		{
			$off[$definition['option']] = false;
		}
		$this->setOptions($off);

		$this->events = new RecordingLogger();
		Emitter::setLogger($this->events);
	}

	protected function tearDown(): void
	{
		Emitter::setLogger(null);

		parent::tearDown();
	}

	protected function enableGroup(string $group): void
	{
		$this->setOption(Emitter::GROUPS[$group]['option'], true);
	}

	/**
	 * Everything emitted, as one string - for asserting that a value appears nowhere in it.
	 */
	protected function emitted(): string
	{
		return json_encode($this->events->records);
	}
}
