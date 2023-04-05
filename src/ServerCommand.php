<?php
declare(strict_types=1);

namespace SocketLog;

use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class ServerCommand extends Command
{
    protected static $defaultName = 'socket-log-server';

    protected function configure()
    {
        $this
            ->setDescription('Socket Log Server');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = $this->getApplication()->getVersion();
        $output->writeln("<info>Socket Log Server.</info>");
        $output->writeln("<info>Version: {$version}</info>");

        $logger = new ConsoleLogger(
            output: $output,
            verbosityLevelMap: [
                LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL,
            ]
        );
        $server = new Server($logger);

        $server->run();

        return Command::SUCCESS;
    }
}
