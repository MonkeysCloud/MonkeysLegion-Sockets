<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Service;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Sockets\Contracts\ChannelAuthorizerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * RoomManager
 * 
 * High-level service for managing public, private, and presence channels.
 * Handles the complexity of authorization and presence event broadcasting.
 */
class RoomManager
{
    public function __construct(
        private readonly ConnectionRegistryInterface $registry,
        private readonly BroadcasterInterface $broadcaster,
        private readonly ?ChannelAuthorizerInterface $authorizer = null,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    /**
     * Join a public channel.
     */
    public function joinPublic(ConnectionInterface $connection, string $room): void
    {
        $tag = "public:{$room}";
        $this->registry->tag($connection, $tag);
        $this->logger->info("Connection {$connection->getId()} joined public room: {$room}");
    }

    /**
     * Join a private channel with authorization.
     */
    public function joinPrivate(ConnectionInterface $connection, string $room, array $parameters = []): bool
    {
        if ($this->authorizer === null) {
            $this->logger->warning("Private join attempted for room [{$room}] but no authorizer is registered.");
            return false;
        }

        if ($this->authorizer->authorize($connection, $room, $parameters)) {
            $tag = "private:{$room}";
            $this->registry->tag($connection, $tag);
            $this->logger->info("Connection {$connection->getId()} authorized to join private room: {$room}");
            return true;
        }

        return false;
    }

    /**
     * Join a presence channel.
     * Automatically notifies other members and provides member list to the joiner.
     * 
     * @return array|false Returns the list of current members if successful, false otherwise.
     */
    public function joinPresence(ConnectionInterface $connection, string $room, array $parameters = []): array|false
    {
        // Presence channels are always private by default in our architecture
        if (!$this->joinPrivate($connection, "presence:{$room}", $parameters)) {
            return false;
        }

        $tag = "private:presence:{$room}";
        
        // Get current members BEFORE notifying about the join (to avoid joiner seeing themselves in the "others" list if not careful, or just for consistency)
        $members = [];
        foreach ($this->registry->getByTag($tag) as $other) {
            if ($other->getId() !== $connection->getId()) {
                $members[] = $this->getMemberData($other);
            }
        }

        // Notify others
        $this->broadcaster->to($tag)->emit('presence:joined', [
            'room' => $room,
            'member' => $this->getMemberData($connection)
        ]);

        return $members;
    }

    /**
     * Leave a channel.
     */
    public function leave(ConnectionInterface $connection, string $channel): void
    {
        $this->registry->untag($connection, $channel);

        // If it was a presence channel, notify others
        if (str_starts_with($channel, 'private:presence:')) {
            $room = substr($channel, 17);
            $this->broadcaster->to($channel)->emit('presence:left', [
                'room' => $room,
                'member_id' => $connection->getId()
            ]);
        }
    }

    /**
     * Get data about a connection for presence events.
     */
    private function getMemberData(ConnectionInterface $connection): array
    {
        $metadata = $connection->getMetadata();
        return [
            'id' => $connection->getId(),
            'info' => $metadata['user_info'] ?? []
        ];
    }
}
