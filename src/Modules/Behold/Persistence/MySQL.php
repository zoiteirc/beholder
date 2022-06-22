<?php

namespace App\Modules\Behold\Persistence;

use App\Persistence\Exceptions\PdoPersistenceException;
use App\Persistence\Exceptions\PersistenceException;
use App\Persistence\Pdo;
use App\Modules\Behold\Stats\ActiveTimeTotals;
use App\Modules\Behold\Stats\QuoteBuffer;
use App\Modules\Behold\Stats\StatTotals;
use App\Modules\Behold\Stats\TextStatsBuffer;

class MySQL extends Pdo implements PersistenceInterface
{
    protected ?array $channelsCache = null;

    public function prepare(): void
    {
        $this->withDatabaseConnection(function (\PDO $connectionResource) {
            $this->checkSchema($connectionResource, 'beholder_schema_version');
        });
    }

    protected function getSchema() : array
    {
        return [
            1 => [
                <<< EOD
                CREATE TABLE `behold_canonical_nicks` (
                  `normalized_nick` varchar(255) NOT NULL DEFAULT '',
                  `regular_nick` varchar(255) NOT NULL DEFAULT '',
                  PRIMARY KEY (`normalized_nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD,
                <<< EOD
                CREATE TABLE `behold_line_counts` (
                  `type` int(11) NOT NULL DEFAULT '0',
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `nick` varchar(255) NOT NULL DEFAULT '',
                  `total` int(11) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`type`, `channel_id`,`nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD,
                <<< EOD
                CREATE TABLE `behold_active_times` (
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `nick` varchar(255) NOT NULL DEFAULT '',
                  `hour` tinyint(2) NOT NULL DEFAULT '0',
                  `total` int(11) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`channel_id`,`nick`,`hour`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD,
                <<< EOD
                CREATE TABLE `behold_channels` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `channel` varchar(255) UNIQUE NOT NULL DEFAULT '',
                  `created_at` int NOT NULL,
                  `updated_at` int NOT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD,
                <<< EOD
                CREATE TABLE `behold_ignored_nicks` (
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `normalized_nick` varchar(255) NOT NULL DEFAULT '',
                  PRIMARY KEY (`normalized_nick`,`channel_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD,
                <<< EOD
                CREATE TABLE `behold_ignored_nicks_global` (
                  `normalized_nick` varchar(255) NOT NULL DEFAULT '',
                  PRIMARY KEY (`normalized_nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD,
                <<< EOD
                CREATE TABLE `behold_latest_quote` (
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `nick` varchar(255) NOT NULL DEFAULT '',
                  `quote` varchar(512) NOT NULL DEFAULT '',
                  PRIMARY KEY (`channel_id`,`nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD,
                <<< EOD
                CREATE TABLE `behold_textstats` (
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `nick` varchar(255) NOT NULL DEFAULT '',
                  `messages` int(11) NOT NULL DEFAULT '0',
                  `words` int(11) NOT NULL DEFAULT '0',
                  `chars` int(11) NOT NULL DEFAULT '0',
                  `avg_words` decimal(5,2) NOT NULL DEFAULT '0.00',
                  `avg_chars` decimal(5,2) NOT NULL DEFAULT '0.00',
                  PRIMARY KEY (`channel_id`,`nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD,
            ],
        ];
    }

    public function persist(
        StatTotals $lineStatsBuffer,
        TextStatsBuffer $textStatsBuffer,
        ActiveTimeTotals $activeTimesBuffer,
        QuoteBuffer $latestQuotesBuffer,
        array $channelList,
        array $ignoreList
    ) : bool
    {
        $this->withDatabaseConnection(
            function (\PDO $connectionResource)
            use (
                $lineStatsBuffer,
                $textStatsBuffer,
                $activeTimesBuffer,
                $latestQuotesBuffer,
                $channelList,
                $ignoreList
            ) {
                $recordedCanonicalNicks = [];

                $statements = [];

                $this->synchronizeChannelList($connectionResource, $channelList);

                $this->synchronizeIgnoreList($connectionResource, $ignoreList);

                foreach ($lineStatsBuffer->getData() as $type => $channels) {
                    foreach ($channels as $chan => $nicks) {
                        foreach ($nicks as $nick => $quantity) {
                            if (!in_array($nick, $recordedCanonicalNicks, true)) {
                                $statements[] = $this->buildNickNormalizationInsertQuery($connectionResource, $nick);
                                $recordedCanonicalNicks[] = $nick;
                            }

                            $statement = $connectionResource->prepare(
                                <<< EOD
                                INSERT INTO `behold_line_counts`
                                SET `type` = :type,
                                    `channel_id` = :channel_id,
                                    `nick` = :nickname,
                                    `total` = :quantity
                                ON DUPLICATE KEY UPDATE
                                    `total` = `total` + :quantity;
                                EOD,
                            );

                            $statement->bindValue('type', $type);
                            $statement->bindValue('channel_id', $this->getChannelId($chan));
                            $statement->bindValue('nickname', $this->normalizeNick($nick));
                            $statement->bindValue('quantity', $quantity);

                            $statements[] = $statement;
                        }
                    }
                }

                foreach ($textStatsBuffer->data() as $nick => $channels) {
                    foreach ($channels as $chan => $totals) {
                        if (!in_array($nick, $recordedCanonicalNicks, true)) {
                            $statements[] = $this->buildNickNormalizationInsertQuery($connectionResource, $nick);
                            $recordedCanonicalNicks[] = $nick;
                        }

                        $statement = $connectionResource->prepare(
                            <<< EOD
                            INSERT INTO `behold_textstats`
                            SET `channel_id` = :channel_id,
                                `nick` = :nickname,
                                `messages` = :quantity_messages,
                                `words` = :quantity_words,
                                `chars` = :quantity_chars,
                                `avg_words` = :quantity_words / :quantity_messages,
                                `avg_chars` = :quantity_chars / :quantity_messages
                            ON DUPLICATE KEY UPDATE
                                `messages` = `messages` + :quantity_messages,
                                `words` = `words` + :quantity_words,
                                `chars` = `chars` + :quantity_chars,
                                `avg_words` = `words` / `messages`,
                                `avg_chars` = `chars` / `messages`;
                            EOD
                        );

                        $statement->bindValue('channel_id', $this->getChannelId($chan));
                        $statement->bindValue('nickname', $this->normalizeNick($nick));
                        $statement->bindValue('quantity_messages', $totals['messages']);
                        $statement->bindValue('quantity_words', $totals['words']);
                        $statement->bindValue('quantity_chars', $totals['chars']);

                        $statements[] = $statement;
                    }
                }

                foreach ($activeTimesBuffer->data() as $nick => $channels) {
                    foreach ($channels as $chan => $hours) {
                        foreach ($hours as $hour => $quantity) {
                            if (!in_array($nick, $recordedCanonicalNicks, true)) {
                                $statements[] = $this->buildNickNormalizationInsertQuery($connectionResource, $nick);
                                $recordedCanonicalNicks[] = $nick;
                            }

                            $statement = $connectionResource->prepare(
                                <<< EOD
                                INSERT INTO `behold_active_times`
                                SET `channel_id` = :channel_id,
                                    `nick` = :nickname,
                                    `hour` = :hour,
                                    `total` = :quantity
                                ON DUPLICATE KEY UPDATE
                                    `total` = `total` + :quantity;
                                EOD,
                            );

                            $statement->bindValue('channel_id', $this->getChannelId($chan));
                            $statement->bindValue('nickname', $this->normalizeNick($nick));
                            $statement->bindValue('hour', $hour);
                            $statement->bindValue('quantity', $quantity);

                            $statements[] = $statement;
                        }
                    }
                }

                foreach ($latestQuotesBuffer->data() as $nick => $channels) {
                    foreach ($channels as $chan => $quote) {
                        if (!in_array($nick, $recordedCanonicalNicks, true)) {
                            $statements[] = $this->buildNickNormalizationInsertQuery($connectionResource, $nick);
                            $recordedCanonicalNicks[] = $nick;
                        }

                        $statement = $connectionResource->prepare(
                            <<< EOD
                            INSERT INTO `behold_latest_quote`
                                SET `channel_id` = :channel_id,
                                    `nick` = :nickname,
                                    `quote` = :quote
                                ON DUPLICATE KEY UPDATE
                                    `quote` = :quote;
                            EOD,
                        );

                        $statement->bindValue('channel_id', $this->getChannelId($chan));
                        $statement->bindValue('nickname', $this->normalizeNick($nick));
                        $statement->bindValue('quote', $quote);

                        $statements[] = $statement;
                    }
                }

                if (count($statements) === 0) {
                    echo 'Nothing to write.' . "\n\r";
                    return;
                } else {
                    echo 'Writing to database (' . count($statements) . ' update' . (count($statements) === 1 ? '' : 's') . ')...';
                }

                $this->withTransaction(
                    $connectionResource,
                    function (\PDO $resourceConnection) use ($statements) {
                        foreach ($statements as $statement) {
                            if (! $statement->execute()) {
                                throw new PdoPersistenceException($resourceConnection);
                            }
                        }
                    }
                );

                echo ' done.' . "\n\r";
            }
        );

        return true;
    }

    protected function synchronizeChannelList(
        \PDO $connectionResource,
        array $channelList
    ): void {
        $statements = [];

        $normalizedChannelList = array_map('strtolower', $channelList);

        foreach ($this->getChannels() as $cachedChannelId => $cachedChannelName) {
            if (! in_array($cachedChannelName, $normalizedChannelList)) {
                // This channel has been removed since we last persisted
                foreach (
                    [
                        'DELETE FROM `behold_line_counts` WHERE `channel_id` = :channel_id',
                        'DELETE FROM `behold_active_times` WHERE `channel_id` = :channel_id',
                        'DELETE FROM `behold_latest_quote` WHERE `channel_id` = :channel_id',
                        'DELETE FROM `behold_textstats` WHERE `channel_id` = :channel_id',
                        'DELETE FROM `behold_channels` WHERE `id` = :channel_id',
                    ] as $sql
                ) {
                    $statements[] = $connectionResource
                        ->prepare($sql)
                        ->bindValue('channel_id', $cachedChannelId);
                }
            }
        }

        $now = time();

        foreach ($channelList as $channel) {
            $statement = $connectionResource
                ->prepare(
                    <<< EOD
                    INSERT INTO `behold_channels`
                    SET
                        `channel` = :channel_name,
                        `created_at` = :now,
                        `updated_at` = :now
                    ON DUPLICATE KEY UPDATE
                        `updated_at` = :now;
                    EOD
                );

            $statement->bindValue('channel_name', $channel);
            $statement->bindValue('now', $now);

            $statements[] = $statement;
        }

        foreach ($statements as $statement) {
            if (! $statement->execute()) {
                throw new PdoPersistenceException($connectionResource);
            }
        }

        if (count($statements) > 0) {
            $this->channelsCache = null;
        }
    }

    protected function synchronizeIgnoreList(\PDO $connectionResource, array $actualList)
    {
        $dbList = $this->fetchIgnoredNicks($connectionResource);

        $statements = [];

        foreach ($actualList['global'] as $actualListNick) {
            if (! in_array($actualListNick, $dbList['global'], true)) {
                $statement = $connectionResource->prepare(
                    <<< EOD
                    INSERT INTO `behold_ignored_nicks_global`
                    SET `normalized_nick` = :nickname
                    EOD,
                );
                $statement->bindValue('nickname', $actualListNick);
                $statements[] = $statement;
            }
        }

        foreach ($dbList['global'] as $dbListNick) {
            if (! in_array($dbListNick, $actualList['global'], true)) {
                $statement = $connectionResource->prepare(
                    <<< EOD
                    DELETE FROM `behold_ignored_nicks_global`
                    WHERE `normalized_nick` = :nickname
                    EOD,
                );
                $statement->bindValue('nickname', $dbListNick);
                $statements[] = $statement;
            }
        }

        foreach ($actualList['channels'] as $channel => $actualListNicks) {
            foreach ($actualListNicks as $actualListNick) {
                if (
                    false === isset($dbList['channels'][$channel])
                    || false === in_array($actualListNick, $dbList['channels'][$channel], true)
                ) {
                    $statement = $connectionResource->prepare(
                        <<< EOD
                        INSERT INTO `behold_ignored_nicks`
                        SET `normalized_nick` = :nickname,
                        `channel_id` = :channel_id
                        EOD,
                    );

                    $statement->bindValue('nickname', $actualListNick);
                    $statement->bindValue('channel_id', $this->getChannelId($channel));

                    $statements[] = $statement;
                }
            }
        }

        foreach ($dbList['channels'] as $channel => $dbListNicks) {
            foreach ($dbListNicks as $dbListNick) {
                if (
                    false === isset($actualList['channels'][$channel])
                    || false === in_array($dbListNick, $actualList['channels'][$channel], true)
                ) {
                    $statement = $connectionResource->prepare(
                        <<< EOD
                        DELETE FROM `behold_ignored_nicks`
                        WHERE `normalized_nick` = :nickname
                        AND `channel_id` = :channel_id
                        EOD,
                    );

                    $statement->bindValue('nickname', $dbListNick);
                    $statement->bindValue('channel_id', $this->getChannelId($channel));

                    $statements[] = $statement;
                }
            }
        }

        foreach ($statements as $statement) {
            if (! $statement->execute()) {
                throw new PdoPersistenceException($connectionResource);
            }
        }
    }

    public function getChannelId($channel) : int
    {
        $channel = strtolower($channel);

        $result = array_search($channel, $this->getChannels());

        if ($result === false) {
            throw new PersistenceException('No such channel');
        }

        return (int) $result;
    }

    protected function simpleQuery(\PDO $connectionResource, string $query): \PDOStatement
    {
        $result = $connectionResource->query($query);

        if (false === $result) {
            throw new PdoPersistenceException($connectionResource);
        }

        return $result;
    }

    protected function fetchIgnoredNicks(\PDO $connectionResource): array
    {
        $ignoredNicks = [
            'channels' => [],
            'global' => [],
        ];

        // Channel level ignores...
        $result = $this->simpleQuery(
            $connectionResource,
            <<< EOD
            SELECT ig.`normalized_nick` AS `nick`, c.`channel`
            FROM `behold_ignored_nicks` ig
            INNER JOIN `behold_channels` c ON c.`id` = ig.`channel_id`
            EOD,
        );

        while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
            $ignoredNicks['channels'][strtolower($row['channel'])][] = $row['nick'];
        }

        // Global ignores...
        $result = $this->simpleQuery(
            $connectionResource,
            <<< EOD
            SELECT `normalized_nick` AS `nick`
            FROM `behold_ignored_nicks_global`
            EOD,
        );

        while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
            $ignoredNicks['global'][] = $row['nick'];
        }

        return $ignoredNicks;
    }

    public function getChannels() : array
    {
        if (is_null($this->channelsCache)) {
            $this->channelsCache = $this->fetchChannelsFromDatabase();
        }

        return $this->channelsCache;
    }

    public function getIgnoredNicks(): array
    {
        return $this->withDatabaseConnection(function (\PDO $connectionResource) {
            return $this->fetchIgnoredNicks($connectionResource);
        });
    }

    protected function fetchChannelsFromDatabase()
    {
        return $this
            ->withDatabaseConnection(function (\PDO $connectionResource) {
                $channels = [];

                $result = $connectionResource
                    ->query('SELECT `id`, `channel` FROM `behold_channels`');

                if (false === $result) {
                    throw new PdoPersistenceException($connectionResource);
                }

                while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
                    $channels[(int) $row['id']] = strtolower($row['channel']);
                }

                return $channels;
            });
    }

    protected function buildNickNormalizationInsertQuery(
        \PDO $connectionResource,
        string $nick
    ): \PDOStatement
    {
        $statement = $connectionResource->prepare(
            <<< EOD
            INSERT INTO `behold_canonical_nicks`
            SET `normalized_nick` = :normalized_nick,
                `regular_nick` = :regular_nick
            ON DUPLICATE KEY UPDATE
                `regular_nick` = :regular_nick;
            EOD
        );

        $statement->bindValue('normalized_nick', $this->normalizeNick($nick));
        $statement->bindValue('regular_nick', $nick);

        return $statement;
    }

    protected function normalizeNick($nick) : string
    {
        return strtolower($nick);
    }
}
