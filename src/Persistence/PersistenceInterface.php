<?php

namespace App\Persistence;

interface PersistenceInterface
{
    public function __construct(array $options);

    public function getChannels() : array;
}
