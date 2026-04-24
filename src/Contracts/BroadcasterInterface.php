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
     * Target a public channel. 
     * Semantically equivalent to to("public:$name").
     */
    public function publicChannel(string $name): self;

    /**
     * Target a private channel. 
     * Semantically equivalent to to("private:$name").
     */
    public function privateChannel(string $name): self;

    /**
     * Target a dynamic channel/tag using pattern binding.
     * Example: channel('User.{id}', ['id' => 1]) targets 'User.1'
     */
    public function channel(string $pattern, array $parameters): self;

    /**
     * Emit the message with optional envelope metadata.
     */
    public function emit(string $event, mixed $data = []): void;

    /**
     * Emit a raw string or message object to the previously specified target.
     */
    public function raw(MessageInterface|string $message): void;
}
