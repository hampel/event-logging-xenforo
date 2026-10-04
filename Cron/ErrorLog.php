<?php namespace Hampel\EventLogging\Cron;

use Hampel\EventLogging\ErrorLogMirror;

class ErrorLog
{
	public static function mirror()
	{
		(new ErrorLogMirror(\XF::app()))->run();
	}
}
