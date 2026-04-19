<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\MessageInterface;
use React\Socket\ConnectionInterface as ReactRawConnection;

/**
 * ReactConnection
 * 
 * Connection wrapper for the ReactPHP Socket ecosystem.
 */
class ReactConnection implements ConnectionInterface
{
    private int $lastActivity;
    private bool $isUpgraded = false;

    public function __construct(
        private readonly ReactRawConnection $connection,
        private array $metadata = []
    ) {
        $this->lastActivity = \time();
    }

    public function getId(): string
    {
        return \spl_object_hash($this->connection);
    }

    public function send(string|MessageInterface $message): void
    {
        $payload = $message instanceof MessageInterface ? $message->getPayload() : $message;
        $this->connection->write($payload);
        $this->touch();
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->connection->end();
    }

    public function lastActivity(): int
    {
        return $this->lastActivity;
    }

    public function touch(): void
    {
        $this->lastActivity = \time();
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isUpgraded(): bool
    {
        return $this->isUpgraded;
    }

    public function setUpgraded(bool $upgraded): void
    {
        $this->isUpgraded = $upgraded;
    }
}
