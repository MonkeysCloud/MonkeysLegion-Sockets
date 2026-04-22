<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

/**
 * Represents an active WebSocket connection.
 */
interface ConnectionInterface
{
    /**
     * Get the unique identifier for this connection.
     */
    public function getId(): string;

    /**
     * Send a message to the client.
     */
    public function send(string|MessageInterface $message): void;

    /**
     * Send a WebSocket Ping frame to the client.
     */
    public function ping(string $payload = ''): void;

    /**
     * Close the connection.
     */
    public function close(int $code = 1000, string $reason = ''): void;

    /**
     * Get the timestamp of the last activity on this connection.
     */
    public function lastActivity(): int;

    /**
     * Update the last activity timestamp to the current time.
     */
    public function touch(): void;

    /**
     * Get metadata associated with the connection (e.g., query params, headers).
     * 
     * @return array<string, mixed>
     */
    public function getMetadata(): array;
}
