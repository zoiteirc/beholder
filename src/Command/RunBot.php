<?php

namespace App\Command;

use App\Client\Bot;
use App\Configuration;
use App\Persistence\MySQL;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunBot extends Command
{
    use LockableTrait;

    protected static $defaultName = 'bot:run';

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->lock()) {
            $output->writeln('The bot is already running.');
            return Command::SUCCESS;
        }

        $bot = new Bot(
            new Configuration(
                $_ENV['BOT_NICK'] ?? 'beholder',
                $_ENV['BOT_USERNAME'] ?? 'beholder',
                $_ENV['BOT_REALNAME'] ?? 'Beholder - IRC Channel Stats Aggregator',

                $_ENV['SERVER_HOSTNAME'] ?? 'irc.zoite.net',
                $_ENV['SERVER_PORT'] ?? 6667,
                $_ENV['USE_TLS'] ?? false,

                $_ENV['NICKSERV_ACCOUNT'] ?? null,
                $_ENV['NICKSERV_PASSWORD'] ?? null,

                $_ENV['BOT_ADMIN_NICK'] ?? null,

                $_ENV['WRITE_FREQUENCY'] ?? 60,
                (bool) $_ENV['DEBUG'] ?? false,
            ),
            new MySQL([
                'hostname' => $_ENV['DB_HOST'] ?? 'localhost',
                'username' => $_ENV['DB_USER'] ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
                'database' => $_ENV['DB_NAME'] ?? 'beholder',
            ])
        );

        $bot->connect();

        return Command::SUCCESS;
    }
}
