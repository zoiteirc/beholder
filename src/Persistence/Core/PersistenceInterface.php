<?php

namespace App\Persistence\Core;

interface PersistenceInterface
{
    public function __construct(array $options);

    public function prepare(): void;

    public function getChannels() : array;

    public function addChannel(string $channelName) : array;

    public function removeChannel(string $channelName) : array;
}
