<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Service;

use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Frame\Frame;

/**
 * HeartbeatManager
 * 
 * Manages WebSocket keep-alive by sending Ping frames to idle connections
 * and closing those that don't respond or timed out.
 */
final readonly class HeartbeatManager
{
    public function __construct(
        private ConnectionRegistryInterface $registry,
        private int $idleTimeout = 60,
        private int $pingInterval = 30
    ) {}

    /**
     * Check all connections and send pings or close timed-out ones.
     */
    public function check(): void
    {
        $now = \time();
        $pingFrame = new Frame('', opcode: 0x9); // Ping opcode

        foreach ($this->registry->all() as $connection) {
            $idleTime = $now - $connection->lastActivity();

            if ($idleTime >= $this->idleTimeout) {
                $connection->close(1006, 'Idle timeout');
                $this->registry->remove($connection);
                continue;
            }

            if ($idleTime >= $this->pingInterval) {
                $connection->send($pingFrame);
            }
        }
    }
}
