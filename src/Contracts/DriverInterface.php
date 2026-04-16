<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

/**
 * Represents a Transport Driver for the WebSocket server.
 */
interface DriverInterface
{
    /**
     * Start the transport server and listen for connections.
     */
    public function listen(string $address, int $port): void;

    /**
     * Stop the transport server.
     */
    public function stop(): void;

    /**
     * Register a callback for when a new connection is opened.
     * 
     * @param callable(ConnectionInterface): void $callback
     */
    public function onOpen(callable $callback): void;

    /**
     * Register a callback for when a message is received.
     * 
     * @param callable(ConnectionInterface, MessageInterface): void $callback
     */
    public function onMessage(callable $callback): void;

    /**
     * Register a callback for when a connection is closed.
     * 
     * @param callable(ConnectionInterface): void $callback
     */
    public function onClose(callable $callback): void;

    /**
     * Register a callback for when an error occurs.
     * 
     * @param callable(ConnectionInterface, \Throwable): void $callback
     */
    public function onError(callable $callback): void;
}
