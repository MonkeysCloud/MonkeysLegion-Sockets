<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

/**
 * ConnectionRegistryInterface
 * 
 * Defines the contract for managing a collection of active WebSocket connections.
 */
interface ConnectionRegistryInterface
{
    /**
     * Add a connection to the registry.
     */
    public function add(ConnectionInterface $connection): void;

    /**
     * Remove a connection from the registry.
     */
    public function remove(string|ConnectionInterface $connection): void;

    /**
     * Get a connection by its unique ID.
     */
    public function get(string $id): ?ConnectionInterface;

    /**
     * Get all active connections.
     * 
     * @return iterable<ConnectionInterface>
     */
    public function all(): iterable;

    /**
     * Count the number of active connections.
     */
    public function count(): int;
}
