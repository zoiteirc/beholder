<?php

namespace App\Client;

use App\ConfigurationInterface;
use App\Persistence\Exceptions\PersistenceException;
use App\Persistence\PersistenceInterface;
use App\Stats\ActiveTimeTotals;
use App\Stats\MonologueMonitor;
use App\Stats\QuoteBuffer;
use App\Stats\StatsTotalsInterface;
use App\Stats\StatTotals;
use App\Stats\TextStatsBuffer;

class Bot extends Client
{
    protected ConfigurationInterface $config;

    protected StatTotals $lineStatsBuffer;
    protected TextStatsBuffer $textStatsBuffer;
    protected ActiveTimeTotals $activeTimesBuffer;
    protected QuoteBuffer $latestQuotesBuffer;
    protected MonologueMonitor $monologueMonitor;
    protected array $ignoreNicks;

    protected $profanities = 'fuck|shit|bitch|cunt|pussy';
    protected $violentWords = 'smacks|beats|punches|hits|slaps';

    protected $lastWriteAt = INF * -1;

    protected $channels = [];

    protected PersistenceInterface $persistence;

    public function __construct(ConfigurationInterface $config, PersistenceInterface $persistence)
    {
        parent::__construct(
            $config->getDesiredNick(),
            ($config->useTls() ? 'tls://' : '') . $config->getHost(),
            $config->getPort(),
        );

        $this->persistence = $persistence;

        $this->config = $config;

        $this->setName($config->getUsername());
        $this->setRealName($config->getRealName());

        $this->initializeIgnoredNicks();

        $this->reconnectInterval = 10;

        $this->initializeChannelsList();

        $this->initializeBuffers();

        $this->registerConnectionHandlingListeners();

        if ($config->isDebugMode()) {
            $this->registerDebugListener();
        }

        $this->registerChannelControlListeners();

        $this->registerIgnoreListControlListeners();

        $this->registerStatsListeners();
    }

    protected function initializeChannelsList()
    {
        $this->channels = $this->persistence->getChannels();
    }

    protected function initializeBuffers()
    {
        $this->lineStatsBuffer = new StatTotals();
        $this->textStatsBuffer = new TextStatsBuffer();
        $this->activeTimesBuffer = new ActiveTimeTotals();
        $this->latestQuotesBuffer = new QuoteBuffer();
        $this->monologueMonitor = new MonologueMonitor();
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

    protected function registerIgnoreListControlListeners()
    {
        if ($this->config->hasBotAdmin()) {
            $this->on('chat', function ($event) {
                if ($event->from != $this->config->getBotAdminNick()) {
                    return;
                }

                if (!$this->isBotWatchingChannel($event->channel)) {
                    return;
                }

                if (
                    preg_match(
                        '/^' . $this->nick . ', please (?<action>ignore|behold) (?<nick>[^\s]+) (?<where>globally|in this channel)$/i',
                        $event->text,
                        $matches
                    )
                ) {
                    if ($matches['action'] === 'ignore') {
                        if ($matches['where'] === 'globally') {
                            // Ignore nick globally
                            $this->addGlobalIgnore($matches['nick']);
                            $this->chat($event->channel, 'Okay, I\'ll ignore ' . $matches['nick'] . ' globally.');
                        } else {
                            // Ignore nick in this channel
                            $this->addChannelIgnore($event->channel, $matches['nick']);
                            $this->chat($event->channel, 'Okay, I\'ll ignore ' . $matches['nick'] . ' in ' . $event->channel . '.');
                        }
                    } else {
                        if ($matches['where'] === 'globally') {
                            // Behold nick globally
                            $this->removeGlobalIgnore($matches['nick']);
                            $this->chat($event->channel, 'Okay, I\'ll stop ignoring ' . $matches['nick'] . ' globally.');
                        } else {
                            // Behold nick in this $channel
                            $this->removeChannelIgnore($event->channel, $matches['nick']);
                            $this->chat($event->channel, 'Okay, I\'ll stop ignoring ' . $matches['nick'] . ' in ' . $event->channel . '.');
                        }
                    }
                }
            });
        }
    }

    protected function initializeIgnoredNicks(): void
    {
        $this->ignoreNicks = $this->persistence->getIgnoredNicks();
    }

    protected function addGlobalIgnore($nick)
    {
        $normalizedNick = strtolower($nick);

        if (!in_array($normalizedNick, $this->ignoreNicks['global'], true)) {
            $this->ignoreNicks['global'][] = $normalizedNick;
        }
    }

    protected function removeGlobalIgnore($nick)
    {
        $normalizedNick = strtolower($nick);

        $foundKey = array_search($normalizedNick, $this->ignoreNicks['global'], true);

        if (false === $foundKey) {
            return;
        }

        unset($this->ignoreNicks['global'][$foundKey]);
    }

    protected function addChannelIgnore($channel, $nick)
    {
        if (!$this->isBotWatchingChannel($channel)) {
            return;
        }

        $normalizedNick = strtolower($nick);
        $normalizedChannel = strtolower($channel);

        if (
            false === isset($this->ignoreNicks['channels'][$normalizedChannel])
            || false === in_array($normalizedNick, $this->ignoreNicks['channels'][$normalizedChannel], true)
        ) {
            $this->ignoreNicks['channels'][$normalizedChannel][] = $normalizedNick;
        }
    }

    protected function removeChannelIgnore($channel, $nick)
    {
        if (!$this->isBotWatchingChannel($channel)) {
            return;
        }

        $normalizedNick = strtolower($nick);
        $normalizedChannel = strtolower($channel);

        $noNicksIgnored = !isset($this->ignoreNicks['channels'][$normalizedChannel]);

        if ($noNicksIgnored) {
            return;
        }

        $foundKey = array_search($normalizedNick, $this->ignoreNicks['channels'][$normalizedChannel], true);

        if (false === $foundKey) {
            return;
        }

        unset($this->ignoreNicks['channels'][$normalizedChannel]);
    }

    protected function isIgnoredNick(string $nick, string $channel)
    {
        $normalizedNick = strtolower($nick);

        if (
            in_array(
                $normalizedNick,
                [
                    strtolower($this->nick),
                    strtolower($this->config->getDesiredNick()),
                ],
                true,
            )
        ) {
            return true;
        }

        $normalizedChannel = strtolower($channel);

        return
            in_array($normalizedNick, $this->ignoreNicks['global'], true)
            || (
                isset($this->ignoreNicks['channels'][$normalizedChannel])
                && in_array($normalizedNick, $this->ignoreNicks['channels'][$normalizedChannel], true)
            );
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
            });
        }
    }

    protected function setUpChannel($channel)
    {
        if ($this->isBotWatchingChannel($channel)) {
            $this->pmBotAdmin('Already in ' . $channel);
            return;
        }

        $this->pmBotAdmin('Joining ' . $channel);

        $this->channels[] = $channel;

        $this->join($channel);
    }

    protected function tearDownChannel($channel)
    {
        if (!$this->isBotWatchingChannel($channel)) {
            $this->pmBotAdmin('Not in ' . $channel);
            return;
        }

        $this->pmBotAdmin('Leaving ' . $channel);

        $normalizedChannel = strtolower($channel);

        foreach ($this->channels as $key => $activeChannel) {
            if (strtolower($activeChannel) === $normalizedChannel) {
                unset($this->channels[$key]);
            }
        }

        $this->part($channel);
    }

    protected function registerStatsListeners()
    {
        $this->on('tick', function () {
            $this->writeToDatabase();
        });

        $this->on('chat', function ($event) {
            if (!$this->isBotWatchingChannel($event->channel)) {
                return;
            }

            $nick = $event->from;
            $channel = $event->channel;
            $message = $event->text;

            $this->recordChatMessage($nick, $channel, $message);
        });

        $this->on('kick', function ($event) {
            if (!$this->isIgnoredNick($event->victim, $event->channel)) {
                $this->lineStatsBuffer->add($event->channel, $event->victim, StatsTotalsInterface::TYPE_KICK_VICTIM);
            }
            if (!$this->isIgnoredNick($event->kicker, $event->channel)) {
                $this->lineStatsBuffer->add($event->channel, $event->kicker, StatsTotalsInterface::TYPE_KICK_PERPETRATOR);
            }
        });

        $this->on('join', function ($event) {
            if ($this->isIgnoredNick($event->nick, $event->channel)) {
                return;
            }

            $this->lineStatsBuffer->add($event->channel, $event->nick, StatsTotalsInterface::TYPE_JOIN);
        });

        $this->on('part', function ($event) {
            if ($this->isIgnoredNick($event->nick, $event->channel)) {
                return;
            }

            $this->lineStatsBuffer->add($event->channel, $event->nick, StatsTotalsInterface::TYPE_PART);
        });

        $this->on('mode', function ($event) {
            if ($this->isIgnoredNick($event->nick, $event->channel)) {
                return;
            }

            $nick = $event->nick;
            $channel = $event->channel;
            $changes = $event->changes;

            foreach ($changes as list($polarity, $mode, $recipient)) {
                if ($mode === 'o') {
                    $this->lineStatsBuffer->add(
                        $channel,
                        $nick,
                        (
                        $polarity === '+'
                            ? StatsTotalsInterface::TYPE_DONATED_OPS
                            : StatsTotalsInterface::TYPE_REVOKED_OPS
                        )
                    );
                }
            }
        });
    }

    protected function writeToDatabase()
    {
        if (time() - $this->lastWriteAt < $this->config->getWriteFrequencySeconds()) {
            return;
        }

        echo 'Writing to database...' . "\n";

        try {
            $persistOperation = $this->persistence->persist(
                $this->lineStatsBuffer,
                $this->textStatsBuffer,
                $this->activeTimesBuffer,
                $this->latestQuotesBuffer,
                $this->channels,
                $this->ignoreNicks,
            );
        } catch (PersistenceException $exception) {
            $this->pmBotAdmin(
                implode(
                    ' ',
                    [
                        'Error encountered while persisting data.',
                        'Code: ' . $exception->getCode(),
                        'Message: ' . $exception->getMessage(),
                    ],
                )
            );
            return;
        }

        if ($persistOperation) {
            $this->textStatsBuffer->reset();
            $this->lineStatsBuffer->reset();
            $this->activeTimesBuffer->reset();
            $this->latestQuotesBuffer->reset();
        } else {
            echo 'Nothing to write.' . "\n";
        }

        $this->lastWriteAt = time();
    }

    protected function recordChatMessage($nick, $channel, $message)
    {
        if ($this->isIgnoredNick($nick, $channel)) {
            return;
        }

        $this->monologueMonitor->spoke($channel, $nick);
        if ($this->monologueMonitor->is_becoming_monologue($channel)) {
            $this->lineStatsBuffer->add($channel, $nick, StatsTotalsInterface::TYPE_MONOLOGUE);
        }

        $this->textStatsBuffer->add($nick, $channel, 1, str_word_count($message, 0, '1234567890'), strlen($message));

        $this->activeTimesBuffer->add($nick, $channel, date('G'));

        if ($this->isProfane($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatsTotalsInterface::TYPE_PROFANITY);
        }

        if ($this->isAction($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatsTotalsInterface::TYPE_ACTION);

            if ($this->isViolentAction($message)) {
                $this->lineStatsBuffer->add($channel, $nick, StatsTotalsInterface::TYPE_VIOLENCE);
            }
        } else {
            $this->latestQuotesBuffer->set($nick, $channel, $message);
        }

        if ($this->isQuestion($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatsTotalsInterface::TYPE_QUESTION);
        }

        if ($this->isShouting($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatsTotalsInterface::TYPE_SHOUT);
        }

        if ($this->isAllCapsMessage($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatsTotalsInterface::TYPE_CAPS);
        }
        if ($this->isSmile($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatsTotalsInterface::TYPE_SMILE);
        }
        if ($this->isFrown($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatsTotalsInterface::TYPE_FROWN);
        }
    }

    protected function isProfane($message)
    {
        return preg_match('/' . $this->profanities . '/', $message);
    }

    protected function isAction($message)
    {
        return preg_match('/^' . chr(1) . 'ACTION.*' . chr(1) . '$/', $message);
    }

    protected function isViolentAction($message)
    {
        return preg_match('/^' . chr(1) . 'ACTION (' . $this->violentWords . ')/', $message);
    }

    protected function isQuestion($message) : bool
    {
        return strpos($message, '?') !== false;
    }

    protected function isShouting($message) : bool
    {
        return strpos($message, '!') !== false;
    }

    protected function isAllCapsMessage($message) : bool
    {
        // All caps lock (and not just a smiley, eg ":D")
        return preg_match_all('/[A-Z]{1}/', $message) > 2 && strtoupper($message) === $message;
    }

    protected function isSmile($message)
    {
        return preg_match('/[:;=8X]{1}[ ^o-]{0,1}[D)>pP\]}]{1}/', $message);
    }

    protected function isFrown($message)
    {
        return preg_match('#[:;=8X]{1}[ ^o-]{0,1}[\(\[\\/\{]{1}#', $message);
    }

    protected function pmBotAdmin($message)
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

    protected function isBotWatchingChannel($channel): bool
    {
        if (!$this->isChannel($channel)) {
            return false;
        }

        $normalizedChannels = array_map('strtolower', $this->channels);
        $normalizedChannel = strtolower($channel);
        return in_array($normalizedChannel, $normalizedChannels);
    }
}
