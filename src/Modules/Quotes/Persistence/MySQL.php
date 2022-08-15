<?php

namespace App\Modules\Quotes\Persistence;

use App\Modules\Quotes\Persistence\Exceptions\PdoPersistenceException;
use App\Persistence\Pdo;

class MySQL extends Pdo implements PersistenceInterface
{
    protected string $hostname;
    protected string $database;
    protected string $username;
    protected string $password;

    public function prepare(): void
    {
        $this->withDatabaseConnection(function (\PDO $connectionResource) {
            $this->checkSchema($connectionResource, 'quotes_schema_version');
        });
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
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
}