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

    /**
     * @param array<string, mixed> $metadata
     */
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
        if (!$this->isUpgraded) {
            // Pre-upgrade: write raw HTTP response bytes (101 Switching Protocols).
            // FrameProcessor must NOT be called here — framing HTTP headers causes 1006.
            $data = $message instanceof MessageInterface ? $message->getPayload() : $message;
            $this->connection->write($data);
            return;
        }

        // Post-upgrade: encode the payload into a proper WebSocket frame.
        $data = $message instanceof MessageInterface
            ? $this->frameProcessor->encode($message->getPayload(), $message->getOpcode())
            : $this->frameProcessor->encode($message);

        // Security: Enforce write buffer size limits (Backpressure).
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

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Merges driver-provided data (e.g. query params, server params) into the
     * connection metadata so application code can read them via getMetadata().
     *
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = [...$this->metadata, ...$metadata];
    }

    public function isUpgraded(): bool
    {
        return $this->isUpgraded;
    }

    public function setUpgraded(bool $upgraded): void
    {
        $this->isUpgraded = $upgraded;
    }

    /**
     * Returns the raw ReactPHP connection for callers that need to write
     * pre-encoded bytes (e.g. Pong frames) directly without double-framing.
     */
    public function getUnderlyingConnection(): ReactRawConnection
    {
        return $this->connection;
    }
}
