<?php

namespace App\Modules\SimpleCommands\Commands;

use App\Client\Bot;
use App\Modules\SimpleCommands\ExplainsCommands;
use App\Modules\SimpleCommands\PerformsSimpleCommands;
use App\Traits\FormatsIrcMessages;

class Wine implements PerformsSimpleCommands, ExplainsCommands
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
        // Provide wine to the required person.
        $target = count($arguments) ? implode(' ', $arguments) : $nick;

        $message = $this->action(
                'hands '
                . $this->bold($target)
                . ' a large glass of wine'
            );

        $this->bot->chat($channel, $message);
    }

    public function getCommandExplanations(): array
    {
        return [$this->triggerWord . ' [target]'];
    }
}