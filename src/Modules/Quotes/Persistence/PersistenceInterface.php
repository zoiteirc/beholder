<?php

namespace App\Modules\Quotes\Persistence;

interface PersistenceInterface
{
    public function prepare(): void;

    public function getQuote($searchTerm = null): ?string;
}