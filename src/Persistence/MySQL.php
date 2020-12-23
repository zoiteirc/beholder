<?php

namespace App\Persistence;

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

    protected array $channelsCache;

    public function __construct(array $options)
    {
        // TODO: Validate the options

        $this->hostname = $options['hostname'];
        $this->username = $options['username'];
        $this->password = $options['password'];
        $this->database = $options['database'];

        $this->withDatabaseConnection(function (\mysqli $db) {
            $this->checkSchema($db);

            $result = $db->query('SELECT `id`, `channel` FROM `channels`');

            if (!$result) {
                throw new \Exception($db->error);
            }

            while ($row = $result->fetch_assoc()) {
                $this->channelsCache[(int)$row['id']] = strtolower($row['channel']);
            }
        });
    }

    public function getChannels() : array
    {
        return $this->channelsCache;
    }

    public function getChannelId($channel) : int
    {
        $channel = strtolower($channel);

        $result = array_search($channel, $this->channelsCache);

        if ($result === false) {
            throw new \Exception('No such channel');
        }

        return (int) $result;
    }

    protected function normalizeNick($nick) : string
    {
        return strtolower($nick);
    }

    public function persist(
        StatTotals $lineStatsBuffer,
        TextStatsBuffer $textStatsBuffer,
        ActiveTimeTotals $activeTimesBuffer,
        QuoteBuffer $latestQuotesBuffer
    ) : bool
    {
        $this->withDatabaseConnection(
            function (\mysqli $db)
            use (
                $lineStatsBuffer,
                $textStatsBuffer,
                $activeTimesBuffer,
                $latestQuotesBuffer
            ) {

                $sql = [];

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
                            if (!$db->query($q)) {
                                throw new \Exception($db->error);
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

        $fn($db);

        $this->disconnect($db);
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
            throw new \Exception($db->connect_error);
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
        $result = $db->query('SHOW TABLES LIKE "beholder_config"');

        if (!$result) {
            throw new \Exception($db->error);
        }

        $isTableMissing = $result->num_rows === 0;

        $result->free();

        if ($isTableMissing) {
            $this->initializeSchema($db);
            return;
        }

        $result = $db->query('SELECT `schema_version` FROM `beholder_config` LIMIT 1');

        if (!$result) {
            throw new \Exception($db->error);
        }

        $result = $result->fetch_assoc();

        if ($result['schema_version'] != $this->dbSchemaVersion) {
            throw new \Exception();
        }
    }

    protected function initializeSchema(\mysqli $db)
    {
        foreach ($this->getSchema() as $dql) {
            if (!$db->query($dql)) {
                throw new \Exception($db->error);
            }
        }

        if (
            !$db->query(
                <<< EOD
                CREATE TABLE `beholder_config` (
                  `schema_version` int(11) DEFAULT NULL,
                  `reporting_time` int(11) DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD
            )
        ) {
            throw new \Exception($db->error);
        }

        if (
            !$db->query(
                'INSERT INTO `beholder_config` SET `schema_version` = 1, `reporting_time` = NULL'
            )
        ) {
            throw new \Exception($db->error);
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
            // TODO: Remove this, manage channels dynamically.
            <<< EOD
            INSERT INTO `channels` SET `channel` = '#antisocial';
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
}