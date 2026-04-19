<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Service\HeartbeatManager;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;

#[CoversClass(HeartbeatManager::class)]
final class HeartbeatManagerTest extends TestCase
{
    #[Test]
    public function it_sends_pings_to_idle_connections(): void
    {
        $registry = $this->createStub(ConnectionRegistryInterface::class);
        $connection = $this->createMock(ConnectionInterface::class);
        
        // Configuration of Mock acting as a Stub for this method
        $connection->method('lastActivity')->willReturn(\time() - 35);
        $registry->method('all')->willReturn([$connection]);

        $connection->expects($this->once())->method('send');
        
        $manager = new HeartbeatManager($registry, 60, 30);
        $manager->check();
    }

    #[Test]
    public function it_closes_timed_out_connections(): void
    {
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $connection = $this->createMock(ConnectionInterface::class);
        
        $connection->method('lastActivity')->willReturn(\time() - 70);
        $registry->method('all')->willReturn([$connection]);

        $connection->expects($this->once())->method('close')->with(1006);
        $registry->expects($this->once())->method('remove')->with($connection);
        
        $manager = new HeartbeatManager($registry, 60, 30);
        $manager->check();
    }
}
