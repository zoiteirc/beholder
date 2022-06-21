<?php

namespace App\Modules\Behold;

use App\Client\Bot;
use App\ConfigurationInterface;
use App\Modules\Behold\Persistence\PersistenceInterface;
use App\Modules\Behold\Stats\StatsTotalsInterface;
use App\Modules\Behold\Stats\ActiveTimeTotals;
use App\Modules\Behold\Stats\MonologueMonitor;
use App\Modules\Behold\Stats\QuoteBuffer;
use App\Modules\Behold\Stats\StatTotals;
use App\Modules\Behold\Stats\TextStatsBuffer;
use App\Modules\BotModule;
use App\Persistence\Exceptions\PersistenceException;

class BeholdModule implements BotModule
{
    protected Bot $bot;
    protected ConfigurationInterface $configuration;
    protected PersistenceInterface $persistence;

    protected StatTotals $lineStatsBuffer;
    protected TextStatsBuffer $textStatsBuffer;
    protected ActiveTimeTotals $activeTimesBuffer;
    protected QuoteBuffer $latestQuotesBuffer;
    protected MonologueMonitor $monologueMonitor;
    protected array $ignoreNicks;

    protected $lastWriteAt = INF * -1;

    protected $channels = [];

    protected $profanities = 'shit|piss|fuck|cunt|cocksucker|turd|twat|asshole|bitch|pussy';
    protected $violentWords = 'smacks|beats|punches|hits|slaps';

    public function __construct(
        Bot $bot,
        ConfigurationInterface $configuration,
        PersistenceInterface $persistence
    ) {
        $this->bot = $bot;
        $this->configuration = $configuration;
        $this->persistence = $persistence;
    }

    public function prepare()
    {
        $this->persistence->prepare();
    }

    public function boot()
    {
        $this->initializeIgnoredNicks();
        $this->initializeChannelsList();
        $this->initializeBuffers();
        $this->registerChannelControlListeners();
        $this->registerIgnoreListControlListeners();
        $this->registerStatsListeners();
    }

    protected function initializeIgnoredNicks(): void
    {
        $this->ignoreNicks = $this->persistence->getIgnoredNicks();
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

    protected function registerChannelControlListeners()
    {
        if ($this->configuration->hasBotAdmin()) {
            $pmToBotFromAdmin = 'pm:' . $this->bot->getNick() . ':' . $this->configuration->getBotAdminNick();
            $this->bot->on($pmToBotFromAdmin, function ($event) {
                if (
                    preg_match(
                        '/^please (?<action>behold|disregard) the channel (?<channel>[^\s]+) now$/',
                        $event->text,
                        $matches,
                    )
                ) {
                    $action = $matches['action'];
                    $channel = $matches['channel'];

                    if (!$this->bot->isChannel($channel)) {
                        $this->bot->pmBotAdmin($channel . ' is not a channel');
                        return;
                    }

                    if ('behold' === $action) {
                        $this->startBeholding($channel);
                    } else if ('disregard' === $action) {
                        $this->stopBeholding($channel);
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

    protected function startBeholding($channel)
    {
        if (! $this->bot->isBotMemberOfChannel($channel)) {
            $this->bot->pmBotAdmin("Sorry, I'm not currently in $channel");
            return;
        }

        if ($this->isBotBeholdingChannel($channel)) {
            $this->bot->pmBotAdmin("I'm already beholding $channel");
            return;
        }

        $this->bot->pmBotAdmin("I will begin beholding $channel");

        $this->channels[] = $channel;
    }

    protected function stopBeholding($channel)
    {
        if (! $this->isBotBeholdingChannel($channel)) {
            $this->bot->pmBotAdmin("I'm already disregarding $channel");
            return;
        }

        $this->bot->pmBotAdmin("From now on I will disregard $channel");

        $normalizedChannel = strtolower($channel);

        foreach ($this->channels as $key => $activeChannel) {
            if (strtolower($activeChannel) === $normalizedChannel) {
                unset($this->channels[$key]);
            }
        }

        $this->lineStatsBuffer->purgeChannel($channel);
        $this->textStatsBuffer->purgeChannel($channel);
        $this->activeTimesBuffer->purgeChannel($channel);
        $this->latestQuotesBuffer->purgeChannel($channel);
        $this->monologueMonitor->purgeChannel($channel);
    }

    protected function registerIgnoreListControlListeners()
    {
        if ($this->configuration->hasBotAdmin()) {
            $this->bot->on('chat', function ($event) {
                if ($event->from != $this->configuration->getBotAdminNick()) {
                    return;
                }

                if (! $this->isBotBeholdingChannel($event->channel)) {
                    return;
                }

                if (
                preg_match(
                    '/^' . $this->bot->getNick() . ', please (?<action>ignore|behold) (?<nick>[^\s]+) (?<where>globally|in this channel)$/i',
                    $event->text,
                    $matches
                )
                ) {
                    if ($matches['action'] === 'ignore') {
                        if ($matches['where'] === 'globally') {
                            // Ignore nick globally
                            $this->addGlobalIgnore($matches['nick']);
                            $this->bot->chat($event->channel, 'Okay, I\'ll ignore ' . $matches['nick'] . ' globally.');
                        } else {
                            // Ignore nick in this channel
                            $this->addChannelIgnore($event->channel, $matches['nick']);
                            $this->bot->chat($event->channel, 'Okay, I\'ll ignore ' . $matches['nick'] . ' in ' . $event->channel . '.');
                        }
                    } else {
                        if ($matches['where'] === 'globally') {
                            // Behold nick globally
                            $this->removeGlobalIgnore($matches['nick']);
                            $this->bot->chat($event->channel, 'Okay, I\'ll stop ignoring ' . $matches['nick'] . ' globally.');
                        } else {
                            // Behold nick in this $channel
                            $this->removeChannelIgnore($event->channel, $matches['nick']);
                            $this->bot->chat($event->channel, 'Okay, I\'ll stop ignoring ' . $matches['nick'] . ' in ' . $event->channel . '.');
                        }
                    }
                }
            });
        }
    }

    protected function isBotBeholdingChannel($channel): bool
    {
        if (!$this->bot->isChannel($channel)) {
            return false;
        }

        $normalizedChannels = array_map('strtolower', $this->channels);
        $normalizedChannel = strtolower($channel);

        return in_array($normalizedChannel, $normalizedChannels);
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
        if (!$this->isBotBeholdingChannel($channel)) {
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
        if (!$this->isBotBeholdingChannel($channel)) {
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

    protected function registerStatsListeners()
    {
        $this->bot->on('tick', function () {
            $this->writeToDatabase();
        });

        $this->bot->on('chat', function ($event) {
            if (! $this->isBotBeholdingChannel($event->channel)) {
                return;
            }

            $nick = $event->from;
            $channel = $event->channel;
            $message = $event->text;

            $this->recordChatMessage($nick, $channel, $message);
        });

        $this->bot->on('kick', function ($event) {
            if (! $this->isBotBeholdingChannel($event->channel)) {
                return;
            }

            if (!$this->isIgnoredNick($event->victim, $event->channel)) {
                $this->lineStatsBuffer->add($event->channel, $event->victim, StatsTotalsInterface::TYPE_KICK_VICTIM);
            }
            if (!$this->isIgnoredNick($event->kicker, $event->channel)) {
                $this->lineStatsBuffer->add($event->channel, $event->kicker, StatsTotalsInterface::TYPE_KICK_PERPETRATOR);
            }
        });

        $this->bot->on('join', function ($event) {
            if (! $this->isBotBeholdingChannel($event->channel)) {
                return;
            }

            if ($this->isIgnoredNick($event->nick, $event->channel)) {
                return;
            }

            $this->lineStatsBuffer->add($event->channel, $event->nick, StatsTotalsInterface::TYPE_JOIN);
        });

        $this->bot->on('part', function ($event) {
            if (! $this->isBotBeholdingChannel($event->channel)) {
                return;
            }

            if ($this->isIgnoredNick($event->nick, $event->channel)) {
                return;
            }

            $this->lineStatsBuffer->add($event->channel, $event->nick, StatsTotalsInterface::TYPE_PART);
        });

        $this->bot->on('mode', function ($event) {
            if (! $this->isBotBeholdingChannel($event->channel)) {
                return;
            }

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
        if (time() - $this->lastWriteAt < $this->configuration->getWriteFrequencySeconds()) {
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
            $this->bot->pmBotAdmin(
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

    protected function isIgnoredNick(string $nick, string $channel)
    {
        $normalizedNick = strtolower($nick);

        if (
        in_array(
            $normalizedNick,
            [
                strtolower($this->bot->getNick()),
                strtolower($this->configuration->getDesiredNick()),
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
}
