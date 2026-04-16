<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;

#[CoversClass(ConnectionRegistry::class)]
final class ConnectionRegistryTest extends TestCase
{
    private ConnectionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ConnectionRegistry();
    }

    #[Test]
    public function it_adds_and_gets_connections(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('getId')->willReturn('conn-1');

        $this->registry->add($connection);
        
        $this->assertSame($connection, $this->registry->get('conn-1'));
        $this->assertCount(1, $this->registry);
    }

    #[Test]
    public function it_removes_connections(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('getId')->willReturn('conn-1');

        $this->registry->add($connection);
        $this->registry->remove('conn-1');
        
        $this->assertNull($this->registry->get('conn-1'));
        $this->assertCount(0, $this->registry);
    }

    #[Test]
    public function it_handles_tagging_and_room_lookups(): void
    {
        $conn1 = $this->createStub(ConnectionInterface::class);
        $conn1->method('getId')->willReturn('1');
        
        $conn2 = $this->createStub(ConnectionInterface::class);
        $conn2->method('getId')->willReturn('2');

        $this->registry->add($conn1);
        $this->registry->add($conn2);
        
        $this->registry->tag($conn1, 'lobby');
        $this->registry->tag($conn2, 'lobby');
        $this->registry->tag($conn1, 'admin');

        $lobby = \iterator_to_array($this->registry->getByTag('lobby'));
        $admin = \iterator_to_array($this->registry->getByTag('admin'));

        $this->assertCount(2, $lobby);
        $this->assertCount(1, $admin);
        $this->assertSame($conn1, $admin[0]);
    }
}
