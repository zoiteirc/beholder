<?php

namespace App\Modules\Quotes\Persistence;

use App\Modules\Quotes\Persistence\Exceptions\PersistenceException;
use App\Modules\Quotes\Persistence\Exceptions\PdoPersistenceException;

class MySQL implements PersistenceInterface
{
    protected string $hostname;
    protected string $database;
    protected string $username;
    protected string $password;

    protected int $expectedSchemaVersion = 1;

    public function __construct(array $options)
    {
        $this->hostname = $options['hostname'];
        $this->username = $options['username'];
        $this->password = $options['password'];
        $this->database = $options['database'];
    }

    public function prepare(): void
    {
        $this->withDatabaseConnection(function (\PDO $connectionResource) {
            $this->checkSchema($connectionResource);
        });
    }

    protected function checkSchema(\PDO $connectionResource)
    {
        $result = $connectionResource->query('SHOW TABLES LIKE "config"');

        if (false === $result) {
            throw new PdoPersistenceException($connectionResource);
        }

        $isTableMissing = $result->rowCount() === 0;

        $result->closeCursor();

        if ($isTableMissing) {
            throw new PersistenceException('No config table found');
        }

        $result = $connectionResource->query(
            <<< EOD
            SELECT `config_value`
            FROM `config`
            WHERE `config_key` = "quotes_schema_version"
            LIMIT 1
            EOD
        );

        if (!$result) {
            throw new PdoPersistenceException($connectionResource);
        }

        if ($result->rowCount() === 0) {
            // No entry, so we can assume the schema isn't set up.
            $this->initializeSchema($connectionResource);
            return;
        }

        $result = $result->fetch(\PDO::FETCH_ASSOC);

        if ($result['config_value'] == $this->expectedSchemaVersion) {
            // Schema version matches.
            return;
        }

        $expected = $this->expectedSchemaVersion;
        $actual = $result['config_value'];

        if ($actual < $expected) {
            // Run migrations?
            throw new PersistenceException(
                'Apparently migrations need to be run... but there are none!'
            );
        }

        throw new PersistenceException(
            "Unexpected schema version ($actual in use, $expected expected)"
        );
    }

    protected function initializeSchema(\PDO $connectionResource)
    {
        $currentSchemaVersion = null;
        foreach ($this->getSchema() as $schemaVersion => $schemaCommands) {
            foreach ($schemaCommands as $schemaCommand) {
                if (! $connectionResource->query($schemaCommand)) {
                    throw new PdoPersistenceException($connectionResource);
                }
            }

            $currentSchemaVersion = $schemaVersion;
        }

        $statement = $connectionResource->prepare(
            <<< EOD
            INSERT INTO `config`
            SET `config_key` = :key,
            `config_value` = :value
            ON DUPLICATE KEY UPDATE `config_value` = :value;
            EOD
        );

        $params = [
            'key' => 'quotes_schema_version',
            'value' => $currentSchemaVersion,
        ];

        if (! $statement->execute($params)) {
            throw new PdoPersistenceException($connectionResource);
        }
    }

    protected function getSchema() : array
    {
        return [
            1 => [
                <<< EOD
                CREATE TABLE `quotes` (
                  `id` INT NOT NULL AUTO_INCREMENT,
                  `content` varchar(400) NOT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                EOD,
            ],
        ];
    }

    public function getQuote($searchTerm = null): ?string
    {
        return $this->withDatabaseConnection(
            function (\PDO $connectionResource) use ($searchTerm) {
                $statement = $connectionResource->prepare(
                    'SELECT * FROM quotes'
                    . (
                    is_null($searchTerm)
                        ? ''
                        : ' WHERE content LIKE :search_term'
                    )
                    . ' ORDER BY RAND() LIMIT 1'
                );

                if (!is_null($searchTerm)) {
                    $searchTerm = '%' . $searchTerm . '%';
                    $statement->bindParam('search_term', $searchTerm);
                }

                if (!$statement->execute()) {
                    throw new PdoPersistenceException($connectionResource);
                }

                if ($statement->rowCount() === 0) {
                    return null;
                }

                $row = $statement->fetch(\PDO::FETCH_ASSOC);

                return $row['content'];
            }
        );
    }

    protected function withDatabaseConnection(callable $fn)
    {
        $connectionResource = $this->connect();

        $result = $fn($connectionResource);

        $connectionResource = null;

        return $result;
    }

    /**
     * @return \PDO
     * @throws \Exception
     */
    protected function connect() : \PDO
    {
        $attempt = 1;
        $maxAttempts = 12;
        $connectionResource = null;
        do {
            if ($attempt > 1) {
                echo "Connecting to database (attempt $attempt of $maxAttempts)\n\r";
            }

            try {
                $connectionResource = new \PDO(
                    'mysql:dbname=' . $this->database . ';host=' . $this->hostname . ';charset=utf8',
                    $this->username,
                    $this->password,
                );
            } catch (\PDOException $exception) {
                sleep(5);
            }
        } while (is_null($connectionResource) && $attempt++ && $attempt < $maxAttempts);

        if (is_null($connectionResource)) {
            throw new \Exception(
                'Could not connect to database',
                0,
                $exception ?? null,
            );
        }

        return $connectionResource;
    }
}