<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\MessageInterface;
use Swoole\WebSocket\Server;

/**
 * SwooleConnection
 * 
 * Connection wrapper for the Swoole WebSocket engine.
 * Leverages Swoole's high-concurrency event-based push system.
 */
class SwooleConnection implements ConnectionInterface
{
    private int $lastActivity;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $fd,
        private readonly Server $server,
        private readonly array $metadata = []
    ) {
        $this->lastActivity = \time();
    }

    public function getId(): string
    {
        return (string) $this->fd;
    }

    public function send(string|MessageInterface $message): void
    {
        $payload = $message instanceof MessageInterface ? $message->getPayload() : $message;
        
        // Swoole handles masking and framing automatically
        if ($this->server->isEstablished($this->fd)) {
            $this->server->push($this->fd, $payload);
        }
        
        $this->touch();
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->server->isEstablished($this->fd)) {
            // Swoole 4.5+ supports disconnect with code and reason
            if (\method_exists($this->server, 'disconnect')) {
                $this->server->disconnect($this->fd, $code, $reason);
            } else {
                $this->server->close($this->fd);
            }
        }
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
}
