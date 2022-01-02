<?php

namespace App\Modules\CommandList;

use App\Client\Bot;
use App\ConfigurationInterface;
use App\Modules\BotModule;
use App\Modules\SimpleCommands\ExplainsCommands;

class CommandListModule implements BotModule
{
    protected Bot $bot;
    protected ConfigurationInterface $config;

    public function __construct(Bot $bot, ConfigurationInterface $config)
    {
        $this->bot = $bot;
        $this->config = $config;
    }

    public function boot()
    {
        $this->bot->on('chat', function ($event) {
            if ($event->text === $this->config->getCommandPrefix() . 'commands') {
                $this->sendCommandList($event->from);
            }
        });
    }

    protected function sendCommandList(string $nick)
    {
        $commandExplanations = $this->bot->reduceModules(
            function (array $carry, BotModule $module) {
                if ($module instanceof ExplainsCommands) {
                    $carry = array_merge($carry, $module->getCommandExplanations());
                }
                return $carry;
            },
            [$this->config->getCommandPrefix() . 'commands - Show this list of commands'],
        );

        foreach ($commandExplanations as $commandExplanation) {
            $this->bot->pm($nick, $commandExplanation);
        }
    }
}