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
    private readonly \MonkeysLegion\Sockets\Service\RoomManager $roomManager;

    public function __construct(
        private readonly ConnectionRegistryInterface $registry,
        private readonly BroadcasterInterface $broadcaster,
        private readonly FormatterInterface $formatter,
        private readonly ?\MonkeysLegion\Sockets\Contracts\ChannelAuthorizerInterface $authorizer = null
    ) {
        $this->roomManager = new \MonkeysLegion\Sockets\Service\RoomManager(
            $this->registry,
            $this->broadcaster,
            $this->authorizer
        );
    }

    /**
     * Join a public channel.
     */
    public function joinPublic(ConnectionInterface|string $connection, string $name): self
    {
        $conn = is_string($connection) ? $this->registry->get($connection) : $connection;
        if ($conn) {
            $this->roomManager->joinPublic($conn, $name);
        }
        return $this;
    }

    /**
     * Join a private channel.
     */
    public function joinPrivate(ConnectionInterface|string $connection, string $name, array $parameters = []): bool
    {
        $conn = is_string($connection) ? $this->registry->get($connection) : $connection;
        return $conn && $this->roomManager->joinPrivate($conn, $name, $parameters);
    }

    /**
     * Join a presence channel.
     * 
     * @return array|false Returns members list if successful, false otherwise.
     */
    public function joinPresence(ConnectionInterface|string $connection, string $name, array $parameters = []): array|false
    {
        $conn = is_string($connection) ? $this->registry->get($connection) : $connection;
        return $conn ? $this->roomManager->joinPresence($conn, $name, $parameters) : false;
    }

    /**
     * Add a connection to a specific room/channel (Generic/Legacy).
     */
    public function join(ConnectionInterface|string $connection, string $room): self
    {
        $this->registry->tag($connection, "room:{$room}");
        return $this;
    }

    /**
     * Leave a public channel.
     */
    public function leavePublic(ConnectionInterface|string $connection, string $name): self
    {
        $conn = is_string($connection) ? $this->registry->get($connection) : $connection;
        if ($conn) {
            $this->roomManager->leave($conn, "public:{$name}");
        }
        return $this;
    }

    /**
     * Leave a private channel.
     */
    public function leavePrivate(ConnectionInterface|string $connection, string $name): self
    {
        $conn = is_string($connection) ? $this->registry->get($connection) : $connection;
        if ($conn) {
            $this->roomManager->leave($conn, "private:{$name}");
        }
        return $this;
    }

    /**
     * Remove a connection from a specific room/channel (Generic/Legacy).
     */
    public function leave(ConnectionInterface|string $connection, string $room): self
    {
        $conn = is_string($connection) ? $this->registry->get($connection) : $connection;
        if ($conn) {
            $this->roomManager->leave($conn, "room:{$room}");
        }
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
