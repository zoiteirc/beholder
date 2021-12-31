<?php

namespace App\Modules\SimpleCommands;

use App\Client\Bot;

class SimpleCommandsModule
{
    protected Bot $bot;

    /** @var string[] */
    protected array $validCommands;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;

        $this->validCommands = [
            'stick' => new Stick($bot),
        ];
    }

    public function boot()
    {
        $this->bot->on('chat', function ($event) {
            if ($this->isCommand($event->text)) {
                $this->triggerCommand($event);
            }
        });
    }

    protected function triggerCommand($event)
    {
        $nick = $event->from;
        $channel = $event->channel;
        $message = $event->text;
        $time = $event->time;

        $commandWord = $this->getCommandWord($message);
        $commandPrefix = '!';
        $arguments = preg_split(
            '/\s+/',
            trim(
                substr(
                    $message,
                    strlen($commandPrefix . $commandWord)
                )
            )
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
        $commandPrefix = '!';

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

        $commandPrefix = '!';

        $messageWithoutPrefix = substr($message, strlen($commandPrefix));

        return explode(' ', $messageWithoutPrefix)[0];
    }

    protected function isValidCommandWord(string $commandWord) : bool
    {
        return isset($this->validCommands[$commandWord]);
    }
}
