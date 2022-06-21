<?php

namespace App\Command;

use App\Client\Bot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunBotCommand extends Command
{
    use LockableTrait;

    protected static $defaultName = 'bot:run';

    protected Bot $bot;

    public function __construct(
        ?string $name,
        Bot $bot
    )
    {
        parent::__construct($name);

        $this->bot = $bot;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->lock()) {
            $output->writeln('The bot is already running.');
            return Command::SUCCESS;
        }

        $this->bot->connect();

        return Command::SUCCESS;
    }
}
