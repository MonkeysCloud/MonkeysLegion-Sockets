<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Registry\RedisConnectionRegistry;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;

#[CoversClass(RedisConnectionRegistry::class)]
final class RedisConnectionRegistryTest extends TestCase
{
    #[Test]
    public function it_synchronizes_tags_with_redis(): void
    {
        $local = $this->createMock(ConnectionRegistryInterface::class);
        $redis = $this->createMock(RedisClientInterface::class);
        $conn = $this->createStub(ConnectionInterface::class);
        $conn->method('getId')->willReturn('conn-1');

        $registry = new RedisConnectionRegistry($local, $redis);

        $local->expects($this->once())->method('tag')->with('conn-1', 'room1');
        $redis->expects($this->exactly(2))->method('sAdd')->willReturn(1);

        $registry->tag($conn, 'room1');
    }

    #[Test]
    public function it_cleans_up_all_redis_tags_on_remove(): void
    {
        $local = $this->createMock(ConnectionRegistryInterface::class);
        $redis = $this->createMock(RedisClientInterface::class);
        
        $registry = new RedisConnectionRegistry($local, $redis);

        // Configuration (Stub mode)
        $redis->method('sMembers')->willReturn(['room1', 'room2']);

        // Verification (Mock mode)
        $redis->expects($this->exactly(2))->method('sRem');
        $redis->expects($this->once())->method('del')->with('ml_sockets:conn_tags:conn-1');
        $local->expects($this->once())->method('remove')->with('conn-1');

        $registry->remove('conn-1');
    }
}
