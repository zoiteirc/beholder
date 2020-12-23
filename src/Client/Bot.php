<?php

namespace App\Client;

use App\Persistence\PersistenceInterface;
use App\Stats\ActiveTimeTotals;
use App\Stats\MonologueMonitor;
use App\Stats\QuoteBuffer;
use App\Stats\StatsTotalsInterface;
use App\Stats\StatTotals;
use App\Stats\TextStatsBuffer;

class Bot extends Client
{
    protected string $desiredNick;

    protected StatTotals $lineStatsBuffer;
    protected TextStatsBuffer $textStatsBuffer;
    protected ActiveTimeTotals $activeTimesBuffer;
    protected QuoteBuffer $latestQuotesBuffer;
    protected MonologueMonitor $monologueMonitor;
    protected array $ignoreNicks;

    protected $profanities = 'fuck|shit|bitch|cunt|pussy';
    protected $violentWords = 'smacks|beats|punches|hits|slaps';

    protected $lastWriteAt = INF * -1;

    protected $writeInterval;

    protected $channels = [];

    protected PersistenceInterface $persistence;

    public function __construct($config, PersistenceInterface $persistence)
    {
        parent::__construct($config['nick'], $config['host'], $config['port']);

        $this->desiredNick = $config['nick'];

        $this->persistence = $persistence;

        $this->initializeChannelsList();

        $this->initializeBuffers();

        $this->writeInterval = $config['write_freq'];

        $this->ignoreNicks = [
            $config['nick'],
        ];

        $this->name = $config['username'];
        $this->realName = $config['realname'];

        $this->reconnectInterval = 10;
        $this->on('disconnected', function () { echo 'Disconnected.' . "\n"; });

        $this->on('message:' . self::ERR_NICKNAMEINUSE, function ($event) {
            $this->nick .= '_';
            $this->nick();
        });

        $this->on('welcome', function () {
            if ($this->nick != $this->desiredNick) {
                // TODO: Given NickServ account details, ghost the old nick and assume it
            }

            foreach ($this->channels as $channel) {
                $this->join($channel);
            }
        });

        if ($config['debug_mode']) {
            $this->on('message', function ($event) {
                echo $event->raw . "\n";
            });
        }

        $this->on('tick', function () {
            $this->writeToDatabase();
        });

        $this->on('chat', function ($event) {
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

    protected function writeToDatabase()
    {
        if (time() - $this->lastWriteAt < $this->writeInterval) {
            return;
        }

        echo 'Writing to database...' . "\n";

        if (
        $this->persistence->persist(
            $this->lineStatsBuffer,
            $this->textStatsBuffer,
            $this->activeTimesBuffer,
            $this->latestQuotesBuffer,
        )
        ) {
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
}
