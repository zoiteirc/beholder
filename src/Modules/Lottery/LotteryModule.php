<?php

namespace App\Modules\Lottery;

use App\Client\Bot;
use App\ConfigurationInterface;
use App\Modules\BotModule;
use App\Modules\Lottery\Exceptions\TicketAlreadyClaimedException;
use App\Modules\Lottery\Persistence\PersistenceInterface;
use App\Modules\SimpleCommands\ExplainsCommands;

class LotteryModule implements BotModule, ExplainsCommands
{
    protected Bot $bot;
    protected ConfigurationInterface $configuration;
    protected PersistenceInterface $persistence;

    public function __construct(
        Bot $bot,
        ConfigurationInterface $configuration,
        PersistenceInterface $persistence
    ) {
        $this->bot = $bot;
        $this->configuration = $configuration;
        $this->persistence = $persistence;
    }

    public function prepare()
    {
        $this->persistence->prepare();
    }

    public function boot()
    {
        $this->bot->on('chat', function ($event) {
            $commandPrefix = $this->configuration->getCommandPrefix();
            if (strpos($event->text, $commandPrefix . 'explain-lottery') === 0) {
                $this->handleExplainCommand($event);
            }

            if (strpos($event->text, $commandPrefix . 'claim-ticket') === 0) {
                $this->handleClaimTicketCommand($event);
            }

            if (strpos($event->text, $commandPrefix . 'lottery-stats') === 0) {
                $this->handleLotteryStatsCommand($event);
            }
        });
    }

    public function getCommandExplanations(): array
    {
        return [
            $this->configuration->getCommandPrefix() . 'claim-ticket - Claim a lottery entry ticket for the current day',
            $this->configuration->getCommandPrefix() . 'explain-lottery - Show a link to a page explaining the lottery',
            $this->configuration->getCommandPrefix() . 'lottery-stats - Show lottery ticket stats',
        ];
    }

    protected function handleExplainCommand($event)
    {
        $message = $event->text;
        $commandPrefix = $this->configuration->getCommandPrefix();

        if ($message !== $commandPrefix . 'explain-lottery') {
            return;
        }

        $this->bot->chat($event->channel, 'Zoite is running a small lottery for users to celebrate our 20th anniversary on July 18th 2022. In the 30 days before the lottery, tickets can be claimed using the !claim-ticket command. More details here: https://zoite.net/lottery');
    }

    protected function handleClaimTicketCommand($event)
    {
        $dateString = $this->getDateString();

        try {
            $success = $this->persistence->claimTicket($event->from, $dateString);
        } catch (TicketAlreadyClaimedException $exception) {
            $this->bot->chat($event->channel, $event->from . ', you have already claimed a ticket for ' . $dateString . '. Each person is allowed to claim 1 ticket each day.');
            return;
        }

        if ($success) {
            $this->bot->chat($event->channel, $event->from . ' has claimed a ticket for ' . $dateString);
        } else {
            $this->bot->chat($event->channel, 'Sorry ' . $event->from . ', there was an unexpected problem! Please make sure staff are aware there has been an issue.');
        }
    }

    protected function handleLotteryStatsCommand($event)
    {
        $dateString = $this->getDateString();
        [$enteredToday, $yourEntryCount] = $this->persistence->getEntryStats($event->from, $dateString);

        $this->bot->chat(
            $event->channel,
            $yourEntryCount === 0
                ? 'Your nick, ' . $event->from . ', has no entries yet!'
                : 'Your nick, ' . $event->from . ', has claimed ' . $yourEntryCount . ' ticket' . ($yourEntryCount === 1 ? '' : 's') . '. You have ' . ($enteredToday ? 'already' : 'not yet') . ' claimed a ticket today.',
        );
    }

    protected function getDateString(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d');
    }
}