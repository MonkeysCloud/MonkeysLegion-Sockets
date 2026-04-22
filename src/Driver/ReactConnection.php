<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\MessageInterface;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use React\Socket\ConnectionInterface as ReactRawConnection;
use RuntimeException;

/**
 * ReactConnection
 * 
 * Connection wrapper for the ReactPHP Socket ecosystem.
 * Handles framing of outgoing data and enforces write buffer limits.
 */
class ReactConnection implements ConnectionInterface
{
    private int $lastActivity;
    private bool $isUpgraded = false;

    public function __construct(
        private readonly ReactRawConnection $connection,
        private readonly FrameProcessor $frameProcessor,
        private readonly int $maxWriteBuffer = 5242880,
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
        // Framework consistent framing for established WebSocket connections
        $data = $message instanceof MessageInterface 
            ? $this->frameProcessor->encode($message->getPayload(), $message->getOpcode())
            : $this->frameProcessor->encode($message);

        // Security: Enforce write buffer size limits (Backpressure)
        // ReactPHP's default buffer is exposed via the connection property if using standard sockets
        // We also check vs the internal writeTallies if needed, but here we can check the stream buffer.
        if (isset($this->connection->buffer) && \property_exists($this->connection->buffer, 'bufferSize')) {
            if ($this->connection->buffer->bufferSize + \strlen($data) > $this->maxWriteBuffer) {
                throw new RuntimeException("Backpressure limit exceeded for React connection {$this->getId()}");
            }
        }

        $this->connection->write($data);
    }

    public function ping(string $payload = ''): void
    {
        if ($this->isUpgraded) {
            $data = $this->frameProcessor->encode($payload, 0x9);
            $this->connection->write($data);
        }
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->isUpgraded) {
            $closeFrame = $this->frameProcessor->encode(
                \pack('n', $code) . $reason,
                0x8
            );
            $this->connection->write($closeFrame);
        }
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
