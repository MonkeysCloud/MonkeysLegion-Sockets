<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Registry;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use Countable;
use IteratorAggregate;
use Traversable;
use WeakMap;

/**
 * ConnectionRegistry
 * 
 * High-performance registry for managing active WebSocket connections.
 * Uses a WeakMap for connection-to-tag mapping to allow automatic 
 * garbage collection and prevent memory leaks if remove() is missed.
 * 
 * @implements IteratorAggregate<string, ConnectionInterface>
 */
final class ConnectionRegistry implements ConnectionRegistryInterface, Countable, IteratorAggregate
{
    /** @var array<string, ConnectionInterface> Primary storage */
    private array $connections = [];

    /** @var array<string, array<string, bool>> Tag mapping [tag => [connectionId => true]] */
    private array $tags = [];

    /** 
     * @var WeakMap<ConnectionInterface, array<string, bool>> 
     * Advanced: Reverse mapping using WeakMap to ensure tag metadata is purged
     * if the connection object itself is garbage collected.
     */
    private WeakMap $connectionTags;

    public function __construct()
    {
        $this->connectionTags = new WeakMap();
    }

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
     */
    public function remove(string|ConnectionInterface $connection): void
    {
        $connObj = $connection instanceof ConnectionInterface ? $connection : ($this->connections[$connection] ?? null);
        if (!$connObj) {
            return;
        }

        $id = $connObj->getId();
        unset($this->connections[$id]);

        // Optimized cleanup using the WeakMap metadata
        $tags = $this->connectionTags[$connObj] ?? [];
        foreach ($tags as $tag => $_) {
            unset($this->tags[$tag][$id]);
            if (empty($this->tags[$tag])) {
                unset($this->tags[$tag]);
            }
        }
        
        // WeakMap entry is automatically eligible for GC, but we can be explicit
        unset($this->connectionTags[$connObj]);
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
        $connObj = $connection instanceof ConnectionInterface ? $connection : ($this->connections[$connection] ?? null);
        if ($connObj) {
            $this->tags[$tag][$connObj->getId()] = true;
            
            if (!isset($this->connectionTags[$connObj])) {
                $this->connectionTags[$connObj] = [];
            }
            // Use array_merge to bypass WeakMap direct modification limitation if needed,
            // but simple assignment works for nested arrays if handled correctly.
            $current = $this->connectionTags[$connObj];
            $current[$tag] = true;
            $this->connectionTags[$connObj] = $current;
        }
    }

    /**
     * Untag a connection (e.g., leave a room).
     */
    public function untag(string|ConnectionInterface $connection, string $tag): void
    {
        $connObj = $connection instanceof ConnectionInterface ? $connection : ($this->connections[$connection] ?? null);
        if ($connObj) {
            unset($this->tags[$tag][$connObj->getId()]);
            
            $current = $this->connectionTags[$connObj] ?? [];
            unset($current[$tag]);
            $this->connectionTags[$connObj] = $current;
        }
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
