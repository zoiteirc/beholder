<?php

namespace App\Modules\Lottery\Persistence;

interface PersistenceInterface
{
    public function prepare(): void;

    public function claimTicket(string $nick, string $dateString): bool;

    public function getEntryStats(string $nick, string $dateString): array;
}