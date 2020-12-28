<?php

namespace App\Client;

use App\Configuration;
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
    protected Configuration $config;

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

    public function __construct(Configuration $config, PersistenceInterface $persistence)
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

        $this->ignoreNicks = [];

        $this->reconnectInterval = 10;

        $this->initializeChannelsList();

        $this->initializeBuffers();

        $this->registerConnectionHandlingListeners();

        if ($config->isDebugMode()) {
            $this->registerDebugListener();
        }

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
            if (!$this->config->hasNickServAccount()) {
                $this->pmBotAdmin(
                    'WARNING: Challenged to identify with NickServ, but no NickServ account details configured. Is this nick already taken?'
                );
                return;
            }

            if (
                false !== strpos(strtolower($event->text), 'is registered')
                && false !== strpos(strtolower($event->text), 'identify via')
            ) {
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
            if (!$this->isIgnoredNick($event->victim)) {
                $this->lineStatsBuffer->add($event->channel, $event->victim, StatsTotalsInterface::TYPE_KICK_VICTIM);
            }
            if (!$this->isIgnoredNick($event->kicker)) {
                $this->lineStatsBuffer->add($event->channel, $event->kicker, StatsTotalsInterface::TYPE_KICK_PERPETRATOR);
            }
        });

        $this->on('join', function ($event) {
            if ($this->isIgnoredNick($event->nick)) {
                return;
            }

            $this->lineStatsBuffer->add($event->channel, $event->nick, StatsTotalsInterface::TYPE_JOIN);
        });

        $this->on('part', function ($event) {
            if ($this->isIgnoredNick($event->nick)) {
                return;
            }

            $this->lineStatsBuffer->add($event->channel, $event->nick, StatsTotalsInterface::TYPE_PART);
        });

        $this->on('mode', function ($event) {
            if ($this->isIgnoredNick($event->nick)) {
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
        if ($this->isIgnoredNick($nick)) {
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

    protected function isIgnoredNick($nick)
    {
        if (
            in_array(
                $nick,
                [
                    $this->nick,
                    $this->config->getDesiredNick(),
                    ]
            )
        ) {
            return true;
        }

        return in_array($nick, $this->ignoreNicks);
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
