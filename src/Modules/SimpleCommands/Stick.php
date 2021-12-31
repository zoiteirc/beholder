<?php

namespace App\Modules\SimpleCommands;

use App\Client\Bot;
use App\Traits\FormatsIrcMessages;

class Stick
{
    use FormatsIrcMessages;

    protected Bot $bot;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
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
        $target = count($arguments) ? $arguments[0] : $nick;

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
}