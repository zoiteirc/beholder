<?php

namespace App\Persistence;

use App\Persistence\Exceptions\PersistenceException;
use App\Stats\ActiveTimeTotals;
use App\Stats\QuoteBuffer;
use App\Stats\StatTotals;
use App\Stats\TextStatsBuffer;

class MySQL implements PersistenceInterface
{
    protected string $hostname;
    protected string $username;
    protected string $password;
    protected string $database;

    protected int $dbSchemaVersion = 1;

    protected ?array $channelsCache = null;

    public function __construct(array $options)
    {
        // TODO: Validate the options

        $this->hostname = $options['hostname'];
        $this->username = $options['username'];
        $this->password = $options['password'];
        $this->database = $options['database'];

        $this->withDatabaseConnection(function (\mysqli $db) {
            $this->checkSchema($db);
        });
    }

    public function getChannels() : array
    {
        if (is_null($this->channelsCache)) {
            $this->channelsCache = $this->fetchChannelsFromDatabase();
        }

        return $this->channelsCache;
    }

    protected function fetchChannelsFromDatabase()
    {
        return $this->withDatabaseConnection(function (\mysqli $db) {
            $channels = [];

            $result = $this->query($db, 'SELECT `id`, `channel` FROM `channels`');

            if (!$result) {
                throw new PersistenceException($db->error, $db->errno);
            }

            while ($row = $result->fetch_assoc()) {
                $channels[(int)$row['id']] = strtolower($row['channel']);
            }

            return $channels;
        });
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

    public function hasChannel($channel): bool
    {
        $channel = strtolower($channel);

        return in_array($channel, $this->getChannels());
    }

    protected function normalizeNick($nick) : string
    {
        return strtolower($nick);
    }

    protected function synchronizeChannelList(\mysqli $db, array $channelList)
    {
        $sql = [];

        $normalizedChannelList = array_map('strtolower', $channelList);
        foreach ($this->getChannels() as $cachedChannelId => $cachedChannelName) {
            if (!in_array($cachedChannelName, $normalizedChannelList)) {
                // This channel has been removed since we last persisted
                $id = $db->escape_string($cachedChannelId);

                $sql[] = 'DELETE FROM `line_counts` WHERE `id` = "' . $id . '"';
                $sql[] = 'DELETE FROM `active_times` WHERE `id` = "' . $id . '"';
                $sql[] = 'DELETE FROM `latest_quote` WHERE `id` = "' . $id . '"';
                $sql[] = 'DELETE FROM `textstats` WHERE `id` = "' . $id . '"';
                $sql[] = 'DELETE FROM `channels` WHERE `id` = "' . $id . '"';
                // TODO: Allow channels to be marked as inactive, and keep their data
            }
        }

        foreach ($channelList as $channel) {
            if (!$this->hasChannel($channel)) {
                // This channel has been added since we last persisted
                $sql[] = <<< EOD
                    INSERT INTO `channels`
                    SET `channel` = "{$db->escape_string($channel)}";
                    EOD;
            }
        }

        foreach ($sql as $q) {
            $this->query($db, $q);
        }

        if (count($sql) > 0) {
            $this->channelsCache = null;
        }
    }

    public function persist(
        StatTotals $lineStatsBuffer,
        TextStatsBuffer $textStatsBuffer,
        ActiveTimeTotals $activeTimesBuffer,
        QuoteBuffer $latestQuotesBuffer,
        array $channelList
    ) : bool
    {
        $this->withDatabaseConnection(
            function (\mysqli $db)
            use (
                $lineStatsBuffer,
                $textStatsBuffer,
                $activeTimesBuffer,
                $latestQuotesBuffer,
                $channelList
            ) {
                $sql = [];

                $this->synchronizeChannelList($db, $channelList);

                foreach ($lineStatsBuffer->getData() as $type => $channels) {
                    foreach ($channels as $chan => $nicks) {
                        foreach ($nicks as $nick => $quantity) {
                            $sql[] = <<< EOD
                                INSERT INTO `line_counts`
                                SET `type` = "{$db->escape_string($type)}",
                                    `channel_id` = "{$this->getChannelId($chan)}",
                                    `nick` = "{$db->escape_string($this->normalizeNick($nick))}",
                                    `total` = "{$db->escape_string($quantity)}"
                                ON DUPLICATE KEY UPDATE
                                    `total` = `total` + "{$db->escape_string($quantity)}";
                            EOD;
                        }
                    }
                }

                foreach ($textStatsBuffer->data() as $nick => $channels) {
                    foreach ($channels as $chan => $totals) {
                        $sql[] = <<< EOD
                            INSERT INTO `textstats`
                            SET `channel_id` = "{$this->getChannelId($chan)}",
                                `nick` = "{$db->escape_string($this->normalizeNick($nick))}",
                                `messages` = "{$db->escape_string($totals['messages'])}",
                                `words` = "{$db->escape_string($totals['words'])}",
                                `chars` = "{$db->escape_string($totals['chars'])}",
                                `avg_words` = {$db->escape_string($totals['words'])}/{$db->escape_string($totals['messages'])},
                                `avg_chars` = {$db->escape_string($totals['chars'])}/{$db->escape_string($totals['messages'])}
                            ON DUPLICATE KEY UPDATE
                                `messages` = `messages` + "{$db->escape_string($totals['messages'])}",
                                `words` = `words` + "{$db->escape_string($totals['words'])}",
                                `chars` = `chars` + "{$db->escape_string($totals['chars'])}",
                                `avg_words` = `words` / `messages`,
                                `avg_chars` = `chars` / `messages`;
                        EOD;
                    }
                }

                foreach ($activeTimesBuffer->data() as $nick => $channels) {
                    foreach ($channels as $chan => $hours) {
                        foreach ($hours as $hour => $quantity) {
                            $sql[] = <<< EOD
                                INSERT INTO `active_times`
                                SET `channel_id` = "{$this->getChannelId($chan)}",
                                    `nick` = "{$db->escape_string($this->normalizeNick($nick))}",
                                    `hour` = "{$db->escape_string($hour)}",
                                    `total` = "{$db->escape_string($quantity)}"
                                ON DUPLICATE KEY UPDATE
                                    `total` = `total` + "{$db->escape_string($quantity)}";
                            EOD;
                        }
                    }
                }

                foreach ($latestQuotesBuffer->data() as $nick => $channels) {
                    foreach ($channels as $chan => $quote) {
                        $sql[] = <<< EOD
                            INSERT INTO `latest_quote`
                                SET `channel_id` = "{$this->getChannelId($chan)}",
                                    `nick` = "{$db->escape_string($this->normalizeNick($nick))}",
                                    `quote` = "{$db->escape_string($quote)}"
                                ON DUPLICATE KEY UPDATE
                                    `quote` = "{$db->escape_string($quote)}";
                        EOD;
                    }
                }

                if (count($sql) === 0) {
                    echo 'Nothing to write.' . "\n\r";
                    return;
                } else {
                    echo 'Writing to database (' . count($sql) . ' update' . (count($sql) === 1 ? '' : 's') . ')...';
                }

                // Record the time of the update
                $sql[] = 'UPDATE `beholder_config` SET `reporting_time`="' . $db->escape_string(time()) . '"';

                $this->withTransaction(
                    $db,
                    function (\mysqli $db) use ($sql) {
                        foreach ($sql as $q) {
                            if (!$this->query($db, $q)) {
                                throw new PersistenceException($db->error, $db->errno);
                            }
                        }
                    }
                );

                echo ' done.' . "\n\r";
            }
        );

        return true;
    }

    protected function withDatabaseConnection(callable $fn)
    {
        $db = $this->connect();

        $result = $fn($db);

        $this->disconnect($db);

        return $result;
    }

    protected function connect() : \mysqli
    {
        $attempt = 1;
        $maxAttempts = 12;
        do {
            if ($attempt > 1) {
                echo 'Connecting to database (attempt ' . ($attempt) . ' of ' . $maxAttempts . ')' . "\n\r";
            }

            $db = @new \mysqli(
                $this->hostname,
                $this->username,
                $this->password,
                $this->database
            );
            if ($db->connect_error) {
                sleep(5);
            }
        } while ($db->connect_error && $attempt++ && $attempt < $maxAttempts);

        if ($db->connect_error) {
            throw new PersistenceException($db->connect_error, $db->connect_errno);
        }

        $db->set_charset('utf8mb4');

        return $db;
    }

    protected function disconnect(\mysqli $db)
    {
        $db->close();
    }

    protected function withTransaction(\mysqli $db, callable $fn)
    {
        $db->begin_transaction();

        try {
            $fn($db);
            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

    protected function checkSchema(\mysqli $db)
    {
        $result = $this->query($db, 'SHOW TABLES LIKE "beholder_config"');

        if (!$result) {
            throw new PersistenceException($db->error, $db->errno);
        }

        $isTableMissing = $result->num_rows === 0;

        $result->free();

        if ($isTableMissing) {
            $this->initializeSchema($db);
            return;
        }

        $result = $this->query($db, 'SELECT `schema_version` FROM `beholder_config` LIMIT 1');

        if (!$result) {
            throw new PersistenceException($db->error, $db->errno);
        }

        $result = $result->fetch_assoc();

        if ($result['schema_version'] != $this->dbSchemaVersion) {
            $expected = $this->dbSchemaVersion;
            $actual = $result['schema_version'];
            throw new PersistenceException(
                'Database schema version mismatch (' . $actual . ' in use, ' . $expected . ' expected'
            );
        }
    }

    protected function initializeSchema(\mysqli $db)
    {
        foreach ($this->getSchema() as $dql) {
            if (!$this->query($db, $dql)) {
                throw new PersistenceException($db->error, $db->errno);
            }
        }

        if (
            !$this->query(
                $db,
                <<< EOD
                CREATE TABLE `beholder_config` (
                  `schema_version` int(11) DEFAULT NULL,
                  `reporting_time` int(11) DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD
            )
        ) {
            throw new PersistenceException($db->error, $db->errno);
        }

        if (
            !$this->query(
                $db,
                'INSERT INTO `beholder_config` SET `schema_version` = 1, `reporting_time` = NULL'
            )
        ) {
            throw new PersistenceException($db->error, $db->errno);
        }
    }

    protected function getSchema() : array
    {
        return [
            <<< EOD
            CREATE TABLE `line_counts` (
              `type` int(11) NOT NULL DEFAULT '0',
              `channel_id` int(11) NOT NULL DEFAULT '0',
              `nick` varchar(255) NOT NULL DEFAULT '',
              `total` int(11) NOT NULL DEFAULT '0',
              PRIMARY KEY (`type`, `channel_id`,`nick`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOD,
            <<< EOD
            CREATE TABLE `active_times` (
              `channel_id` int(11) NOT NULL DEFAULT '0',
              `nick` varchar(255) NOT NULL DEFAULT '',
              `hour` tinyint(2) NOT NULL DEFAULT '0',
              `total` int(11) NOT NULL DEFAULT '0',
              PRIMARY KEY (`channel_id`,`nick`,`hour`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOD,
            <<< EOD
            CREATE TABLE `channels` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `channel` varchar(255) UNIQUE NOT NULL DEFAULT '',
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOD,
            <<< EOD
            CREATE TABLE `ignore_nick` (
              `nick` varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`nick`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOD,
            <<< EOD
            CREATE TABLE `latest_quote` (
              `channel_id` int(11) NOT NULL DEFAULT '0',
              `nick` varchar(255) NOT NULL DEFAULT '',
              `quote` varchar(512) NOT NULL DEFAULT '',
              PRIMARY KEY (`channel_id`,`nick`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOD,
            <<< EOD
            CREATE TABLE `textstats` (
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
        ];
    }

    protected function query(\mysqli $db, $query)
    {
        if (isset($_ENV['MYSQL_DEBUG']) && $_ENV['MYSQL_DEBUG']) {
            echo $query . "\n\r";
        }
        return $db->query($query);
    }
}
