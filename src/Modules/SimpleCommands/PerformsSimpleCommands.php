<?php

namespace App\Modules\SimpleCommands;

interface PerformsSimpleCommands
{
    /**
     * @param int $timestamp
     * @param string $nick
     * @param string $channel
     * @param array<string> $arguments
     */
    public function trigger(int $timestamp, string $nick, string $channel, array $arguments);
}