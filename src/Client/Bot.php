<?php

namespace App\Client;

use App\ConfigurationInterface;
use App\Modules\Behold\BeholdModule;
use App\Modules\CommandList\CommandListModule;
use App\Modules\Lottery\LotteryModule;
use App\Modules\Quotes\Persistence\MySQL;
use App\Modules\Quotes\QuotesModule;
use App\Modules\SimpleCommands\SimpleCommandsModule;
use App\Persistence\Core\PersistenceInterface;

class Bot extends Client
{
    protected ConfigurationInterface $config;

    protected array $channels = [];

    protected PersistenceInterface $persistence;

    protected array $modules;

    public function __construct(ConfigurationInterface $config, PersistenceInterface $persistence)
    {
        parent::__construct(
            $config->getDesiredNick(),
            ($config->useTls() ? 'tls://' : '') . $config->getHost(),
            $config->getPort(),
        );

        $this->persistence = $persistence;

        $this->config = $config;

        $this->persistence->prepare();

        $this->setName($config->getUsername());
        $this->setRealName($config->getRealName());

        $this->reconnectInterval = 10;

        $this->initializeChannelsList();

        $this->registerConnectionHandlingListeners();

        if ($config->isDebugMode()) {
            $this->registerDebugListener();
        }

        $this->registerChannelControlListeners();

        $this->modules = [
            new SimpleCommandsModule($this, $config),
            new CommandListModule($this, $config),
            new QuotesModule(
                $this,
                $config,
                new MySQL($config->getDatabaseCredentials()),
            ),
            new BeholdModule(
                $this,
                $config,
                new \App\Modules\Behold\Persistence\MySQL($config->getDatabaseCredentials())
            ),
        ];

        $this->mapModules(function ($module) {
            $module->prepare();
        });

        $this->mapModules(function ($module) {
            $module->boot();
        });
    }

    public function mapModules(callable $closure)
    {
        return array_map($closure, $this->modules);
    }

    public function reduceModules(callable $closure, $initial = null)
    {
        return array_reduce($this->modules, $closure, $initial);
    }

    protected function initializeChannelsList()
    {
        $this->channels = $this->persistence->getChannels();
    }

    protected function registerConnectionHandlingListeners()
    {
        $this->on('disconnected', function () { echo 'Disconnected.' . "\n"; });

        $this->on('message:' . self::ERR_NICKNAMEINUSE, function ($event) {
            $this->nick .= '_';
            $this->nick();
        });

        // Identify if challenged
        $this->on('notice:' . $this->getNick() . ':NickServ,pm:' . $this->getNick() . ':NickServ', function ($event) {
            if (
                false !== strpos(strtolower($event->text), 'is registered')
                && false !== strpos(strtolower($event->text), 'identify via')
            ) {
                if (!$this->config->hasNickServAccount()) {
                    $this->pmBotAdmin(
                        'WARNING: Challenged to identify with NickServ, but no NickServ account details configured. Is this nick already taken?'
                    );
                    return;
                }

                $this->pm('NickServ', 'IDENTIFY ' . $this->config->getNickServAccountName() . ' ' . $this->config->getNickServPassword());
            }
        });

        $this->on('welcome', function () {
            // Get our nick, if we can
            if ($this->config->hasNickServAccount()) {
                if ($this->nick != $this->config->getDesiredNick()) {
                    $this->pm('NickServ', 'GHOST ' . $this->config->getNickServAccountName() . ' ' . $this->config->getNickServPassword());
                    $this->pm('NickServ', 'RELEASE ' . $this->config->getNickServAccountName() . ' ' . $this->config->getNickServPassword());
                    $this->nick($this->config->getDesiredNick());
                }
            }

            // Join channels
            foreach ($this->channels as $channel) {
                $this->join($channel);
            }
        });
    }

    protected function registerDebugListener()
    {
        $this->on('message', function ($event) {
            echo $event->raw . "\n";
        });
    }

    protected function registerChannelControlListeners()
    {
        if ($this->config->hasBotAdmin()) {
            $this->on('pm:' . $this->getNick() . ':' . $this->config->getBotAdminNick(), function ($event) {
                if (preg_match('/^please (?<action>join|leave) (?<channel>[^\s]+) now$/', $event->text, $matches)) {
                    $action = $matches['action'];
                    $channel = $matches['channel'];

                    if (!$this->isChannel($channel)) {
                        $this->pmBotAdmin($channel . ' is not a channel');
                        return;
                    }

                    if ('join' === $action) {
                        $this->setUpChannel($channel);
                    } else if ('leave' === $action) {
                        $this->tearDownChannel($channel);
                    }
                }

                if ('mysql debug on' === $event->text) {
                    $_ENV['MYSQL_DEBUG'] = true;
                }

                if ('mysql debug off' === $event->text) {
                    $_ENV['MYSQL_DEBUG'] = false;
                }
            });
        }
    }

    protected function setUpChannel($channel)
    {
        if ($this->isBotMemberOfChannel($channel)) {
            $this->pmBotAdmin('Already in ' . $channel);
            return;
        }

        $this->pmBotAdmin('Joining ' . $channel);

        $this->join($channel);

        $this->channels = $this->persistence->addChannel($channel);
    }

    protected function tearDownChannel($channel)
    {
        if (!$this->isBotMemberOfChannel($channel)) {
            $this->pmBotAdmin('Not in ' . $channel);
            return;
        }

        $this->pmBotAdmin('Leaving ' . $channel);

        $this->part($channel);

        $normalizedChannel = strtolower($channel);

        foreach ($this->channels as $activeChannel) {
            if (strtolower($activeChannel) === $normalizedChannel) {
                $channelNameToRemove = $activeChannel;
            }
        }

        $this->channels = $this->persistence->removeChannel($channelNameToRemove);
    }

    public function pmBotAdmin($message)
    {
        echo $message;

        if (!$this->config->hasBotAdmin()) {
            return;
        }

        // Remove new lines and carriage returns
        $message = str_replace(["\r", "\n"], ' ', $message);

        $this->pm(
            $this->config->getBotAdminNick(),
            $message,
        );
    }

    public function isBotMemberOfChannel($channel): bool
    {
        if (!$this->isChannel($channel)) {
            return false;
        }

        $normalizedChannels = array_map('strtolower', $this->channels);
        $normalizedChannel = strtolower($channel);
        return in_array($normalizedChannel, $normalizedChannels);
    }
}
