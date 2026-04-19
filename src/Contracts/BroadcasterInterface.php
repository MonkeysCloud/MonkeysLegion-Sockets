<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

/**
 * BroadcasterInterface
 * 
 * Defines the contract for sending messages to the WebSocket cluster 
 * from external sources (e.g., PHP-FPM, CLI commands).
 */
interface BroadcasterInterface
{
    /**
     * Broadcast a message to all connected clients.
     */
    public function broadcast(MessageInterface|string $message): void;

    /**
     * Send a message to specific connections tagged with the given tag.
     */
    public function to(string $tag): self;

    /**
     * Send a message to a specific connection by ID.
     */
    public function toConnection(string $connectionId): self;

    /**
     * Emit the message with optional envelope metadata.
     */
    public function emit(string $event, mixed $data = []): void;

    /**
     * Emit a raw string or message object to the previously specified target.
     */
    public function raw(MessageInterface|string $message): void;
}
