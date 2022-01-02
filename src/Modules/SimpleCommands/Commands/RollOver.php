<?php

namespace App\Modules\SimpleCommands\Commands;

use App\Client\Bot;
use App\Modules\SimpleCommands\ExplainsCommands;
use App\Modules\SimpleCommands\PerformsSimpleCommands;
use App\Traits\FormatsIrcMessages;

class RollOver implements PerformsSimpleCommands, ExplainsCommands
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
        $this->bot->chat($channel, $this->action('rolls over'));
    }

    public function getCommandExplanations(): array
    {
        return [$this->triggerWord];
    }
}