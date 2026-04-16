<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\MessageInterface;
use MonkeysLegion\Sockets\Frame\FrameProcessor;

/**
 * StreamConnection
 * 
 * Represents a client connection over a PHP stream.
 * Handles reading/writing frames via the StreamSocketDriver.
 */
final class StreamConnection implements ConnectionInterface
{
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
    }

    /**
     * Get the unique connection ID (usually remote address).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Send a raw string or Message object to the client.
     */
    public function send(string|MessageInterface $message): void
    {
        $data = $message instanceof MessageInterface 
            ? $this->frameProcessor->encode($message->getPayload(), $message->getOpcode())
            : $this->frameProcessor->encode($message);

        @\fwrite($this->resource, $data);
    }

    /**
     * Close the connection with an optional status code and reason.
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
     * Get associated metadata (headers, query string, etc.).
     * 
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Internal: Get the raw stream resource.
     * 
     * @return resource
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }
}
