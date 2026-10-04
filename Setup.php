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
		if (!ErrorLogMirror::isSupported())
		{
			$warnings[] = "XenForo 2.4 and later log server errors through Monolog themselves, so this "
				. "add-on's Log server errors option does nothing there. This version has not been tested "
				. "on XenForo 2.4 or later.";
		}
	}
}
