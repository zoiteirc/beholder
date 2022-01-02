<?php

namespace App;

interface ConfigurationInterface
{
    public function getDesiredNick(): string;

    public function getUsername(): string;

    public function getRealName(): string;

    public function getHost(): string;

    public function getPort(): int;

    public function useTls(): bool;

    public function hasNickServAccount(): bool;

    public function getNickServAccountName(): ?string;

    public function getNickServPassword(): ?string;

    public function hasBotAdmin(): bool;

    public function getBotAdminNick(): ?string;

    public function getWriteFrequencySeconds(): int;

    public function isDebugMode(): bool;

    public function getCommandPrefix(): string;
}
