<?php

namespace App\Modules\Quotes;

use App\Client\Bot;
use App\ConfigurationInterface;
use App\Modules\BotModule;
use App\Modules\Quotes\Persistence\PersistenceInterface;
use App\Modules\SimpleCommands\ExplainsCommands;

class QuotesModule implements BotModule, ExplainsCommands
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
            if (strpos($event->text, $commandPrefix . 'quote') === 0) {
                $this->handleQuoteCommand($event);
            }
        });
    }

    public function getCommandExplanations(): array
    {
        return [
            $this->configuration->getCommandPrefix() . 'quote - Get a random quote',
            $this->configuration->getCommandPrefix() . 'quote [search_term] - Search for a specific quote',
        ];
    }

    protected function handleQuoteCommand($event)
    {
        $message = $event->text;
        $commandPrefix = $this->configuration->getCommandPrefix();

        $searchTerm = trim(
            substr(
                $message,
                strlen($commandPrefix . 'quote')
            )
        );

        $quote = $this->persistence->getQuote(strlen($searchTerm) ? $searchTerm : null);

        if (is_null($quote)) {
            $this->bot->notice($event->from, 'No matching quotes.');
            return;
        }

        $this->bot->chat($event->channel, $quote);
    }
}