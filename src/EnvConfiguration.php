<?php

namespace App;

use Symfony\Component\Dotenv\Dotenv;

class EnvConfiguration implements ConfigurationInterface
{
    private string $desiredNick;
    private string $username;
    private string $realName;

    private string $host;
    private int $port;
    private bool $useTls;

    private ?string $nickServAccountName;
    private ?string $nickServPassword;

    private ?string $botAdminNick;

    private int $writeFrequencySeconds;
    private bool $debugMode;

    private string $commandPrefix;

    private array $databaseCredentials;

    public function __construct(Dotenv $dotenv)
    {
        $dotenv->load('.env');

        $this->desiredNick = $_ENV['BOT_NICK'] ?? 'beholder';
        $this->username = $_ENV['BOT_USERNAME'] ?? 'beholder';
        $this->realName = $_ENV['BOT_REALNAME'] ?? 'Beholder - IRC Channel Stats Aggregator';

        $this->host = $_ENV['SERVER_HOSTNAME'] ?? 'irc.zoite.net';
        $this->port = $_ENV['SERVER_PORT'] ?? 6667;
        $this->useTls = (bool) $_ENV['USE_TLS'] ?? false;

        $this->nickServAccountName = $_ENV['NICKSERV_ACCOUNT'] ?? null;
        $this->nickServPassword = $_ENV['NICKSERV_PASSWORD'] ?? null;

        $this->botAdminNick = $_ENV['BOT_ADMIN_NICK'] ?? null;

        $this->writeFrequencySeconds = $_ENV['WRITE_FREQUENCY'] ?? 60;
        $this->debugMode = (bool) $_ENV['DEBUG'] ?? false;

        $this->commandPrefix = (string) $_ENV['COMMAND_PREFIX'] ?? '!';

        $this->databaseCredentials = [
            'hostname' => $_ENV['DB_HOST'] ?? 'db',
            'username' => $_ENV['DB_USER'] ?? 'appuser',
            'password' => $_ENV['DB_PASSWORD'] ?? 'appsecret',
            'database' => $_ENV['DB_NAME'] ?? 'app',
        ];
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

    public function hasBotAdmin(): bool
    {
        return !is_null($this->botAdminNick);
    }

    public function getBotAdminNick(): ?string
    {
        return $this->botAdminNick;
    }

    public function getWriteFrequencySeconds(): int
    {
        return $this->writeFrequencySeconds;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    public function getCommandPrefix(): string
    {
        return $this->commandPrefix;
    }

    public function getDatabaseCredentials(): array
    {
        return $this->databaseCredentials;
    }
}
