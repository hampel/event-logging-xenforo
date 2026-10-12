<?php namespace Hampel\EventLogging\Cli\Command;

use Hampel\EventLogging\Cli\RendersReport;
use Hampel\EventLogging\Emitter;
use Hampel\EventLogging\ErrorLogMirror;
use Hampel\EventLogging\MonologSettings;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Which events this forum sends to its logs, and whether the log file will take them. It reads
 * and prints; it writes nothing. event-logging:validate is the command that finds out whether
 * any of it works.
 */
class Config extends Command
{
	use RendersReport;

	protected function configure()
	{
		$this
			->setName('event-logging:config')
			->setDescription('Show which events this forum sends to its logs');
	}

	protected function errorLogPrefix(): string
	{
		return Emitter::ERROR_PREFIX . ' ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;

		$this->heading('Event groups');
		foreach (Emitter::GROUPS AS $group => $definition)
		{
			$this->detail($group, Emitter::enabled($group)
				? '<info>on</info> ' . $this->muted('- ' . implode(', ', $definition['events']))
				: $this->muted('off'));
		}

		if (Emitter::enabled('admin'))
		{
			$this->detail('admin keep-alives', \XF::options()->offsetGet('eventLoggingAdminSkipKeepAlive') ? 'skipped' : 'recorded');
		}

		$this->heading('Log file');
		$this->detail('channel', Emitter::CHANNEL);

		if (Emitter::logger() instanceof NullLogger)
		{
			// its options are still in the database and still readable, and describe a log file
			// nothing is being written to
			$this->detail('Monolog add-on', '<error>not installed, disabled, or older than 5.0.1 - no event is logged</error>');
		}
		else
		{
			$this->detail('path', (string) MonologSettings::filePath());

			$level = MonologSettings::fileLevel();
			$this->detail('level', $level === null ? '' : MonologSettings::levelName($level) . ' ' . $this->muted(
				MonologSettings::hasChannelLevel() ? "(config.php, for the " . Emitter::CHANNEL . ' channel)' : '(every channel)'
			));
			$this->detail('format', (string) MonologSettings::format());
		}

		$this->heading('Server error log');
		if (!ErrorLogMirror::isSupported())
		{
			$this->detail('mirror', 'not used - this forum has no server error log table');
		}
		else
		{
			$position = (new ErrorLogMirror(\XF::app()))->position();
			$this->detail('mirror', Emitter::enabled('errors') ? 'on' : $this->muted('off'));
			$this->detail('last error sent', $position['last'] === null ? '' : "#{$position['last']}" . (
				$position['pastEnd'] ? ' ' . $this->muted("(past the end of the log, #{$position['max']})") : ''
			));
		}

		$this->report->writeln('');

		return 0;
	}
}
