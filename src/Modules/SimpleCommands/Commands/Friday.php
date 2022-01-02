<?php

namespace App\Modules\SimpleCommands\Commands;

use App\Client\Bot;
use App\Modules\SimpleCommands\ExplainsCommands;
use App\Modules\SimpleCommands\PerformsSimpleCommands;
use App\Traits\FormatsIrcMessages;

class Friday implements PerformsSimpleCommands, ExplainsCommands
{
    use FormatsIrcMessages;

    protected Bot $bot;
    protected string $triggerWord;

    public function __construct(Bot $bot, $triggerWord)
    {
        $this->bot = $bot;
        $this->triggerWord = $triggerWord;
    }

    /**
     * @param int $timestamp
     * @param string $nick
     * @param string $channel
     * @param array<string> $arguments
     */
    public function trigger(int $timestamp, string $nick, string $channel, array $arguments)
    {
        switch (date('l')) {
            case 'Friday':
                // It's Friday!
                $this->bot->chat($channel, 'woohoo friday!');
                break;
            case 'Saturday':
            case 'Sunday':
                $this->bot->chat($channel, 'it\'s not friday... but it is still the weekend :)');
                break;
            default:
                $this->bot->chat($channel, 'it\'s not friday yet :(');
                break;
        }
    }

    public function getCommandExplanations(): array
    {
        return [$this->triggerWord];
    }
}