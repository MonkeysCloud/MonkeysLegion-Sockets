<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Registry;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * ConnectionRegistry
 * 
 * High-performance registry for managing active WebSocket connections.
 * Optimized for O(1) removals via bidirectional tag mapping.
 * 
 * @implements IteratorAggregate<string, ConnectionInterface>
 */
final class ConnectionRegistry implements ConnectionRegistryInterface, Countable, IteratorAggregate
{
    /** @var array<string, ConnectionInterface> Primary storage */
    private array $connections = [];

    /** @var array<string, array<string, bool>> Tag mapping [tag => [connectionId => true]] */
    private array $tags = [];

    /** @var array<string, array<string, bool>> Reverse mapping [connectionId => [tag => true]] */
    private array $connectionTags = [];

    /**
     * Number of active connections.
     */
    public int $count {
        get => \count($this->connections);
    }

    /**
     * Add a connection to the registry.
     */
    public function add(ConnectionInterface $connection): void
    {
        $this->connections[$connection->getId()] = $connection;
    }

    /**
     * Remove a connection from the registry and clean up tags.
     * Fixed Complexity DoS vulnerability (uses reverse mapping for O(1) tag cleanup).
     */
    public function remove(string|ConnectionInterface $connection): void
    {
        $id = $connection instanceof ConnectionInterface ? $connection->getId() : $connection;
        
        unset($this->connections[$id]);

        // Optimized cleanup using reverse mapping
        $tags = $this->connectionTags[$id] ?? [];
        foreach ($tags as $tag => $_) {
            unset($this->tags[$tag][$id]);
            if (empty($this->tags[$tag])) {
                unset($this->tags[$tag]);
            }
        }
        unset($this->connectionTags[$id]);
    }

    /**
     * Get a connection by its unique ID.
     */
    public function get(string $id): ?ConnectionInterface
    {
        return $this->connections[$id] ?? null;
    }

    /**
     * Tag a connection (e.g., join a room).
     */
    public function tag(string|ConnectionInterface $connection, string $tag): void
    {
        $id = $connection instanceof ConnectionInterface ? $connection->getId() : $connection;
        if (isset($this->connections[$id])) {
            $this->tags[$tag][$id] = true;
            $this->connectionTags[$id][$tag] = true;
        }
    }

    /**
     * Untag a connection (e.g., leave a room).
     */
    public function untag(string|ConnectionInterface $connection, string $tag): void
    {
        $id = $connection instanceof ConnectionInterface ? $connection->getId() : $connection;
        unset($this->tags[$tag][$id], $this->connectionTags[$id][$tag]);
    }

    /**
     * Get all connections belonging to a specific tag.
     * 
     * @return iterable<ConnectionInterface>
     */
    public function getByTag(string $tag): iterable
    {
        $members = $this->tags[$tag] ?? [];
        foreach ($members as $id => $_) {
            if (isset($this->connections[$id])) {
                yield $this->connections[$id];
            }
        }
    }

    /**
     * Get all active connections.
     */
    public function all(): iterable
    {
        return $this->connections;
    }

    /**
     * Required by Countable.
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Required by IteratorAggregate.
     */
    public function getIterator(): Traversable
    {
        yield from $this->connections;
    }
}
