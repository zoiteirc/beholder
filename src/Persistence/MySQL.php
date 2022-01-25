<?php

namespace App\Persistence;

use App\Persistence\Exceptions\PersistenceException;

class MySQL implements PersistenceInterface
{
    protected string $hostname;
    protected string $username;
    protected string $password;
    protected string $database;

    protected int $dbSchemaVersion = 2;

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
            if (
                $result['schema_version'] == 1
                && $this->dbSchemaVersion == 2
            ) {
                $this->createGeneralConfigTable($db);
                $this->query($db, 'UPDATE `beholder_config` SET `schema_version` = 2');
                return;
            }

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

        $this->createGeneralConfigTable($db);
    }

    protected function createGeneralConfigTable(\mysqli $db)
    {
        $ddlStatements = [
            <<< EOD
            CREATE TABLE `config` (
              `config_key` varchar(255) NOT NULL DEFAULT '',
              `config_value` varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`config_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOD,
        ];

        foreach ($ddlStatements as $ddl) {
            if (!$this->query($db, $ddl)) {
                throw new PersistenceException($db->error, $db->errno);
            }
        }
    }

    protected function getSchema() : array
    {
        return [
            <<< EOD
            CREATE TABLE `canonical_nicks` (
              `normalized_nick` varchar(255) NOT NULL DEFAULT '',
              `regular_nick` varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`normalized_nick`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOD,
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
              `created_at` int NOT NULL,
              `updated_at` int NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOD,
            <<< EOD
            CREATE TABLE `ignored_nicks` (
              `channel_id` int(11) NOT NULL DEFAULT '0',
              `normalized_nick` varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`normalized_nick`,`channel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOD,
            <<< EOD
            CREATE TABLE `ignored_nicks_global` (
              `normalized_nick` varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`normalized_nick`)
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
