<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\MessageInterface;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use RuntimeException;

/**
 * StreamConnection
 * 
 * Represents a client connection over a PHP stream.
 * Handles reading/writing frames via the StreamSocketDriver.
 * Implements write buffering and backpressure to prevent loop stalls.
 */
final class StreamConnection implements ConnectionInterface
{
    /** @var int Maximum write buffer size (5MB) before killing connection */
    public const int MAX_WRITE_BUFFER = 5 * 1024 * 1024;

    private int $lastActivity;
    private string $writeBuffer = '';

    /**
     * @param resource $resource The raw PHP stream resource.
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly mixed $resource,
        private readonly string $id,
        private readonly FrameProcessor $frameProcessor,
        private readonly array $metadata = []
    ) {
        if (!\is_resource($this->resource)) {
            throw new \InvalidArgumentException('Valid resource expected');
        }
        $this->lastActivity = \time();
    }

    /**
     * Get the unique connection ID (usually remote address).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Add data to the write buffer.
     * Implements backpressure by throwing exception if buffer is too large.
     */
    public function send(string|MessageInterface $message): void
    {
        $data = $message instanceof MessageInterface 
            ? $this->frameProcessor->encode($message->getPayload(), $message->getOpcode())
            : $this->frameProcessor->encode($message);

        if (\strlen($this->writeBuffer) + \strlen($data) > self::MAX_WRITE_BUFFER) {
            throw new RuntimeException("Backpressure limit exceeded for connection {$this->id}");
        }

        $this->writeBuffer .= $data;
        $this->touch();
    }

    /**
     * Attempt to flush the write buffer to the socket.
     * Called by the Driver when the socket becomes writable.
     */
    public function flush(): int
    {
        if ($this->writeBuffer === '') {
            return 0;
        }

        $written = @\fwrite($this->resource, $this->writeBuffer);
        
        if ($written === false || $written === 0) {
            return 0;
        }

        $this->writeBuffer = \substr($this->writeBuffer, $written);
        $this->touch();
        
        return $written;
    }

    /**
     * Check if there is pending data in the write buffer.
     */
    public function hasPendingWrites(): bool
    {
        return $this->writeBuffer !== '';
    }

    /**
     * Close the connection.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        $closeFrame = $this->frameProcessor->encode(
            \pack('n', $code) . $reason,
            0x8 // Close opcode
        );

        @\fwrite($this->resource, $closeFrame);
        @\fclose($this->resource);
    }

    /**
     * Get metadata associated with the connection.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get the raw stream resource.
     * 
     * @return resource
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }

    public function lastActivity(): int
    {
        return $this->lastActivity;
    }

    public function touch(): void
    {
        $this->lastActivity = \time();
    }
}
