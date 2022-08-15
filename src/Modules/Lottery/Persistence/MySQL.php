<?php

namespace App\Modules\Lottery\Persistence;

use App\Modules\Lottery\Exceptions\TicketAlreadyClaimedException;
use App\Modules\Lottery\Persistence\Exceptions\PdoPersistenceException;
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
            $this->checkSchema($connectionResource, 'lottery_schema_version');
        });
    }

    protected function getSchema() : array
    {
        return [
            1 => [
                <<< EOD
                CREATE TABLE `lottery_tickets` (
                  `id` INT NOT NULL AUTO_INCREMENT,
                  `date_string` varchar(10) NOT NULL,
                  `nick` varchar(400) NOT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `unique_date_string_nick` (`date_string`, `nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
            ],
        ];
    }

    public function claimTicket(string $nick, string $dateString): bool
    {
        return $this->withDatabaseConnection(
            function (\PDO $connectionResource) use ($nick, $dateString) {
                return $this->withTransaction(
                    $connectionResource,
                    function (\PDO $connectionResource) use ($nick, $dateString) {
                        $statement = $connectionResource->prepare(
                            <<< EOD
                            SELECT `id`
                            FROM `lottery_tickets`
                            WHERE `date_string` = :date_string
                                AND `nick` = :nick
                            EOD,
                        );

                        $statement->bindParam('nick', $nick);
                        $statement->bindParam('date_string', $dateString);

                        if (!$statement->execute()) {
                            throw new PdoPersistenceException($connectionResource);
                        }

                        if ($statement->rowCount() > 0) {
                            throw new TicketAlreadyClaimedException();
                        }

                        $statement = $connectionResource->prepare(
                            <<< EOD
                            INSERT INTO `lottery_tickets`
                            SET
                                `date_string` = :date_string,
                                `nick` = :nick
                            EOD
                        );

                        $statement->bindParam('nick', $nick);
                        $statement->bindParam('date_string', $dateString);

                        if (!$statement->execute()) {
                            throw new PdoPersistenceException($connectionResource);
                        }

                        if ($statement->rowCount() === 0) {
                            throw new PdoPersistenceException($connectionResource);
                        }

                        return true;
                    }
                );
            }
        );
    }

    public function getEntryStats(string $nick, string $dateString): array
    {
        return $this->withDatabaseConnection(
            function (\PDO $connectionResource) use ($nick, $dateString) {
                $statement = $connectionResource->prepare(
                    <<< EOD
                    SELECT COUNT(`id`) AS `total`
                    FROM `lottery_tickets`
                    WHERE `nick` = :nick
                    EOD,
                );

                $statement->bindParam('nick', $nick);

                if (!$statement->execute()) {
                    throw new PdoPersistenceException($connectionResource);
                }

                $total = $statement->fetchAll()[0]['total'];

                $statement = $connectionResource->prepare(
                    <<< EOD
                    SELECT COUNT(`id`) AS `total`
                    FROM `lottery_tickets`
                    WHERE `nick` = :nick
                    AND `date_string` = :date_string
                    EOD,
                );

                $statement->bindParam('nick', $nick);
                $statement->bindParam('date_string', $dateString);

                if (!$statement->execute()) {
                    throw new PdoPersistenceException($connectionResource);
                }

                $hasEntryForToday = $statement->fetchAll()[0]['total'] > 0;

                return [$hasEntryForToday, (int) $total];
            }
        );
    }
}