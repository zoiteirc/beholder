<?php

namespace App\Persistence\Core;

use App\Persistence\Exceptions\PdoPersistenceException;
use App\Persistence\Pdo as Base;
use PDO;

class MySQL extends Base implements PersistenceInterface
{
    protected ?array $channelsCache = null;

    public function prepare(): void
    {
        $this->withDatabaseConnection(function (\PDO $connectionResource) {
            $this->checkSchema($connectionResource, 'core_schema_version');
        });
    }

    protected function getSchema(): array
    {
        return [
            1 => [
                <<< EOD
                CREATE TABLE `core_channels` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `channel` varchar(255) UNIQUE NOT NULL DEFAULT '',
                  `created_at` int NOT NULL,
                  `updated_at` int NOT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
            ],
        ];
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
        return $this->withDatabaseConnection(function (PDO $connectionResource) {
            return $this->fetchChannels($connectionResource);
        });
    }

    protected function fetchChannels(PDO $connectionResource)
    {
        $channels = [];

        $result = $connectionResource
            ->query('SELECT `id`, `channel` FROM `core_channels`');

        if (false === $result) {
            throw new PdoPersistenceException($connectionResource);
        }

        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $channels[(int)$row['id']] = strtolower($row['channel']);
        }

        return $channels;
    }

    public function addChannel(string $channelName) : array
    {
        return $this->withDatabaseConnection(function (PDO $connectionResource) use ($channelName) {
            $statement = $connectionResource
                ->prepare(
                    <<< EOD
                    INSERT INTO `core_channels`
                    SET `channel` = :channel_name, `created_at` = :now, `updated_at` = :now
                    EOD
                );

            $statement->bindValue('channel_name', $channelName);
            $statement->bindValue('now', time());

            try {
                $statement->execute();
            } catch (\Exception $exception) {
                throw new PdoPersistenceException($connectionResource, $exception);
            }

            return $this->fetchChannels($connectionResource);
        });
    }

    public function removeChannel(string $channelName) : array
    {
        return $this->withDatabaseConnection(function (PDO $connectionResource) use ($channelName) {
            $statement = $connectionResource
                ->prepare(
                    <<< EOD
                    DELETE FROM `core_channels`
                    WHERE `channel` = :channel_name
                    EOD
                );

            $statement->bindValue('channel_name', $channelName);

            try {
                $statement->execute();
            } catch (\Exception $exception) {
                throw new PdoPersistenceException($connectionResource, $exception);
            }

            return $this->fetchChannels($connectionResource);
        });
    }
}