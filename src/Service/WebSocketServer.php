<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Service;

use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\MessageInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * WebSocketServer
 * 
 * The main orchestrator for the WebSocket lifecycle.
 * Bridges the Transport Driver with the Registry and external Broadcasters.
 */
class WebSocketServer
{
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly ConnectionRegistryInterface $registry,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->setupCallbacks();
    }

    /**
     * Start the server.
     */
    public function start(string $address = '0.0.0.0', int $port = 8080): void
    {
        $this->logger->info("MonkeysLegion WebSocket Server starting on $address:$port");
        $this->driver->listen($address, $port);
    }

    private function setupCallbacks(): void
    {
        $this->driver->onOpen(function (ConnectionInterface $connection) {
            $this->registry->add($connection);
            $this->logger->debug("Connection opened: " . $connection->getId());
        });

        $this->driver->onMessage(function (ConnectionInterface $connection, MessageInterface $message) {
            $connection->touch();
            // Future: Hook for Application-level events
        });

        $this->driver->onClose(function (ConnectionInterface $connection) {
            $this->registry->remove($connection);
            $this->logger->debug("Connection closed: " . $connection->getId());
        });

        $this->driver->onError(function (ConnectionInterface $connection, \Throwable $e) {
            $this->logger->error("Socket error on {$connection->getId()}: " . $e->getMessage());
        });
    }
}
