<?php

namespace App\Modules\SimpleCommands;

use App\Client\Bot;
use App\ConfigurationInterface;
use App\Modules\BotModule;
use App\Modules\SimpleCommands\Commands\Beer;
use App\Modules\SimpleCommands\Commands\Bourbon;
use App\Modules\SimpleCommands\Commands\Burger;
use App\Modules\SimpleCommands\Commands\Friday;
use App\Modules\SimpleCommands\Commands\Hug;
use App\Modules\SimpleCommands\Commands\Knai;
use App\Modules\SimpleCommands\Commands\Llama;
use App\Modules\SimpleCommands\Commands\PlayDead;
use App\Modules\SimpleCommands\Commands\RollOver;
use App\Modules\SimpleCommands\Commands\Soda;
use App\Modules\SimpleCommands\Commands\Stick;
use App\Modules\SimpleCommands\Commands\WagTail;
use App\Modules\SimpleCommands\Commands\Wine;

class SimpleCommandsModule implements BotModule, ExplainsCommands
{
    protected Bot $bot;

    /** @var string[] */
    protected array $validCommands;
    protected ConfigurationInterface $config;

    public function __construct(Bot $bot, ConfigurationInterface $config)
    {
        $this->bot = $bot;
        $this->config = $config;

        $commandPrefix = $this->config->getCommandPrefix();

        $this->validCommands = [
            'stick' => new Stick($bot, $commandPrefix . 'stick'),
            'llama' => new Llama($bot, $commandPrefix . 'llama'),
            'hug' => new Hug($bot, $commandPrefix . 'hug'),
            'beer' => new Beer($bot, $commandPrefix . 'beer'),
            'bourbon' => new Bourbon($bot, $commandPrefix . 'bourbon'),
            'wine' => new Wine($bot, $commandPrefix . 'wine'),
            'soda' => new Soda($bot, $commandPrefix . 'soda'),
            'burger' => new Burger($bot, $commandPrefix . 'burger'),
            'knai' => new Knai($bot, $commandPrefix . 'knai'),
            'friday' => new Friday($bot, $commandPrefix . 'friday'),
            'rollover' => new RollOver($bot, $commandPrefix . 'rollover'),
            'playdead' => new PlayDead($bot, $commandPrefix . 'playdead'),
            'wagtail' => new WagTail($bot, $commandPrefix . 'wagtail'),
        ];
    }

    public function prepare()
    {
        // ...
    }

    public function boot()
    {
        $this->bot->on('chat', function ($event) {
            if ($this->isCommand($event->text)) {
                $this->triggerCommand($event);
            }
        });
    }

    public function getCommandExplanations(): array
    {
        return array_reduce(
            $this->validCommands,
            function (array $carry, ExplainsCommands $command) {
                return array_merge($carry, $command->getCommandExplanations());
            },
            [],
        );
    }

    protected function triggerCommand($event)
    {
        $nick = $event->from;
        $channel = $event->channel;
        $message = $event->text;
        $time = $event->time;

        $commandWord = $this->getCommandWord($message);
        $commandPrefix = $this->config->getCommandPrefix();
        $arguments = array_filter(
            preg_split(
                '/\s+/',
                trim(
                    substr(
                        $message,
                        strlen($commandPrefix . $commandWord)
                    )
                )
            ),
            function ($str) {
                return trim($str) !== '';
            }
        );

        $this->validCommands[$commandWord]
            ->trigger(
                $time,
                $nick,
                $channel,
                $arguments
            );
    }

    protected function isCommand($message) : bool
    {
        if (!$this->looksLikeCommand($message)) {
            return false;
        }

        $commandWord = $this->getCommandWord($message);

        return $this->isValidCommandWord($commandWord);
    }

    protected function looksLikeCommand($message) : bool
    {
        $commandPrefix = $this->config->getCommandPrefix();

        $usesCommandPrefix = strpos($message, $commandPrefix) !== 0;

        if ($usesCommandPrefix) {
            return false;
        }

        return strlen($message) > strlen($commandPrefix);
    }

    protected function getCommandWord($message) : string
    {
        if (!$this->looksLikeCommand($message)) {
            throw new \Exception();
        }

        $commandPrefix = $this->config->getCommandPrefix();

        $messageWithoutPrefix = substr($message, strlen($commandPrefix));

        return explode(' ', $messageWithoutPrefix)[0];
    }

    protected function isValidCommandWord(string $commandWord) : bool
    {
        return isset($this->validCommands[$commandWord]);
    }
}
