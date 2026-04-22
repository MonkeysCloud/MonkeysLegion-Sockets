<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Server;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Sockets\Contracts\FormatterInterface;

/**
 * WebSocketServer
 * 
 * The central master orchestrator for the MonkeysLegion WebSocket cluster.
 * Manages connections, security, and the high-level Room/Channel logic.
 */
class WebSocketServer
{
    public function __construct(
        private readonly ConnectionRegistryInterface $registry,
        private readonly BroadcasterInterface $broadcaster,
        private readonly FormatterInterface $formatter
    ) {}

    /**
     * Add a connection to a specific room/channel.
     */
    public function join(ConnectionInterface|string $connection, string $room): self
    {
        $this->registry->tag($connection, "room:{$room}");
        return $this;
    }

    /**
     * Remove a connection from a specific room/channel.
     */
    public function leave(ConnectionInterface|string $connection, string $room): self
    {
        $this->registry->untag($connection, "room:{$room}");
        return $this;
    }

    /**
     * Target a specific room for the next broadcast.
     */
    public function to(string $room): BroadcasterInterface
    {
        return $this->broadcaster->to("room:{$room}");
    }

    /**
     * Target a specific connection directly.
     */
    public function toConnection(string $id): BroadcasterInterface
    {
        return $this->broadcaster->toConnection($id);
    }

    /**
     * Global broadcast to all connected clients.
     */
    public function broadcast(string $event, mixed $data = []): void
    {
        $this->broadcaster->emit($event, $data);
    }

    /**
     * Get the active connection registry.
     */
    public function getRegistry(): ConnectionRegistryInterface
    {
        return $this->registry;
    }

    /**
     * Get the payload formatter.
     */
    public function getFormatter(): FormatterInterface
    {
        return $this->formatter;
    }
}
