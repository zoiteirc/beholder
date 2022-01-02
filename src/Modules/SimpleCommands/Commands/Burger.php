<?php

namespace App\Modules\SimpleCommands\Commands;

use App\Client\Bot;
use App\Modules\SimpleCommands\ExplainsCommands;
use App\Modules\SimpleCommands\PerformsSimpleCommands;
use App\Traits\FormatsIrcMessages;

class Burger implements PerformsSimpleCommands, ExplainsCommands
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
        // Provide sustenance to the required person (or the caller if no one else specified).
        $target = count($arguments) ? implode(' ', $arguments) : $nick;

        if (strpos(strtolower($target), 'elvis') !== false) {
            // Elvis has had enough burgers
            if ($nick === $target) {
                $this->bot->chat($channel, 'I think maybe you\'ve had enough burgers, sir.');
            } else {
                $this->bot->chat($channel, 'Elvis probably doesn\'t need another burger.');
            }
            return;
        }

        $message = $this->action('makes up big a bacon double cheeseburger for ' . $this->bold($target));

        $this->bot->chat($channel, $message);
    }

    public function getCommandExplanations(): array
    {
        return [$this->triggerWord . ' [target]'];
    }
}