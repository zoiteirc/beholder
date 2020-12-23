<?php

namespace App;

class Configuration
{
    private string $desiredNick;
    private string $username;
    private string $realName;

    private string $host;
    private int $port;
    private bool $useTls;

    private ?string $nickServAccountName;
    private ?string $nickServPassword;

    private int $writeFrequencySeconds;
    private bool $debugMode;

    public function __construct(
        string $desiredNick,
        string $username,
        string $realName,

        string $host,
        int $port,
        bool $useTls,

        string $nickServAccountName = null,
        string $nickServPassword = null,

        int $writeFrequencySeconds = 60,

        bool $debugMode = false
    )
    {
        $this->desiredNick = $desiredNick;
        $this->username = $username;
        $this->realName = $realName;

        $this->host = $host;
        $this->port = $port;
        $this->useTls = $useTls;

        $this->nickServAccountName = $nickServAccountName;
        $this->nickServPassword = $nickServPassword;

        $this->writeFrequencySeconds = $writeFrequencySeconds;

        $this->debugMode = $debugMode;
    }

    public function getDesiredNick(): string
    {
        return $this->desiredNick;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getRealName(): string
    {
        return $this->realName;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function useTls(): bool
    {
        return $this->useTls;
    }

    public function hasNickServAccount(): bool
    {
        return !is_null($this->getNickServPassword());
    }

    public function getNickServAccountName(): ?string
    {
        return $this->nickServAccountName;
    }

    public function getNickServPassword(): ?string
    {
        return $this->nickServPassword;
    }

    public function getWriteFrequencySeconds(): int
    {
        return $this->writeFrequencySeconds;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }
}
