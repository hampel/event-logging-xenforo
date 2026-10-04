<?php namespace Hampel\MonologEvents\Cron;

use Hampel\MonologEvents\ErrorLogMirror;

class ErrorLog
{
	public static function mirror()
	{
		(new ErrorLogMirror(\XF::app()))->run();
	}
}
