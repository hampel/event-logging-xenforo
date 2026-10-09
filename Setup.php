<?php namespace Hampel\EventLogging;

use XF\AddOn\AbstractSetup;

/**
 * No tables and no columns: this add-on keeps nothing of its own. Its one piece of state, the
 * last server error sent, is in the simple cache, which XenForo removes on uninstall.
 */
class Setup extends AbstractSetup
{
	public function install(array $stepParams = [])
	{
		// Nothing to do
	}

	public function upgrade(array $stepParams = [])
	{
		// Nothing to do
	}

	public function uninstall(array $stepParams = [])
	{
		// Nothing to do
	}

	public function checkRequirements(&$errors = [], &$warnings = [])
	{
		// a warning, not an error, so a forum on a newer XenForo can still install or upgrade
		if (\XF::$versionId >= 2040000)
		{
			$warnings[] = "This version of Event Logging for Monolog has been tested on XenForo 2.2 and 2.3 "
				. "only. It is expected to work, but check for a newer version that has been tested on yours.";
		}
	}
}
