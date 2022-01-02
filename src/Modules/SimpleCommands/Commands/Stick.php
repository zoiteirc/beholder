<?php

namespace App\Modules\SimpleCommands\Commands;

use App\Client\Bot;
use App\Modules\SimpleCommands\ExplainsCommands;
use App\Modules\SimpleCommands\PerformsSimpleCommands;
use App\Traits\FormatsIrcMessages;

class Stick implements PerformsSimpleCommands, ExplainsCommands
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
        // Hit the required person... or the caller if no other nick is provided.
        $target = count($arguments) ? implode(' ', $arguments) : $nick;

        $this->bot->chat(
            $channel,
            $this->action(
                'beats '
                . $this->bold($target)
                . ' down with a large stick... '
                . $this->bold('owned!')
            )
        );
    }

    public function getCommandExplanations(): array
    {
        return [$this->triggerWord . ' [target]'];
    }
}