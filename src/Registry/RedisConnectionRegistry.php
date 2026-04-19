<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Registry;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;

/**
 * RedisConnectionRegistry
 * 
 * A distributed connection registry that stores 'Tags' (Rooms) in Redis.
 * Shared state allows multiple nodes to identify which connections are in which rooms.
 */
final readonly class RedisConnectionRegistry implements ConnectionRegistryInterface
{
    private const string TAG_PREFIX = 'ml_sockets:tags:';
    private const string CONN_TAGS_PREFIX = 'ml_sockets:conn_tags:';

    public function __construct(
        private ConnectionRegistryInterface $localRegistry,
        private RedisClientInterface $redis,
    ) {}

    /**
     * Add a connection to the local registry.
     */
    public function add(ConnectionInterface $connection): void
    {
        $this->localRegistry->add($connection);
    }

    /**
     * Remove a connection from local and all distributed Redis tags.
     */
    public function remove(string|ConnectionInterface $connection): void
    {
        $id = $connection instanceof ConnectionInterface ? $connection->getId() : $connection;
        
        // 1. Find all tags this connection joined in Redis
        $connTagsKey = self::CONN_TAGS_PREFIX . $id;
        $tags = $this->redis->sMembers($connTagsKey);

        // 2. Remove the connection ID from each tag set
        foreach ($tags as $tag) {
            $this->redis->sRem(self::TAG_PREFIX . $tag, $id);
        }

        // 3. Clean up the connection-tags tracking set
        $this->redis->del($connTagsKey);

        // 4. Finally, remove from local memory
        $this->localRegistry->remove($id);
    }

    /**
     * Join a distributed room/tag.
     */
    public function tag(string|ConnectionInterface $connection, string $tag): void
    {
        $id = $connection instanceof ConnectionInterface ? $connection->getId() : $connection;
        
        // Double-write: Local (for immediate O(1) broadcast) and Redis (for cluster state)
        $this->localRegistry->tag($id, $tag);
        
        $this->redis->sAdd(self::TAG_PREFIX . $tag, $id);
        $this->redis->sAdd(self::CONN_TAGS_PREFIX . $id, $tag);
    }

    /**
     * Leave a distributed room/tag.
     */
    public function untag(string|ConnectionInterface $connection, string $tag): void
    {
        $id = $connection instanceof ConnectionInterface ? $connection->getId() : $connection;
        
        $this->localRegistry->untag($id, $tag);
        
        $this->redis->sRem(self::TAG_PREFIX . $tag, $id);
        $this->redis->sRem(self::CONN_TAGS_PREFIX . $id, $tag);
    }

    /**
     * Retrieve connections for a tag. 
     * Iterates through Redis members and yields local matches.
     */
    public function getByTag(string $tag): iterable
    {
        $ids = $this->redis->sMembers(self::TAG_PREFIX . $tag);

        foreach ($ids as $id) {
            if ($conn = $this->localRegistry->get($id)) {
                yield $conn;
            }
        }
    }

    public function get(string $id): ?ConnectionInterface
    {
        return $this->localRegistry->get($id);
    }

    public function all(): iterable
    {
        return $this->localRegistry->all();
    }

    public function count(): int
    {
        return $this->localRegistry->count();
    }
}
