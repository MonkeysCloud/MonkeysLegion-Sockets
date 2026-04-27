<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Registry;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Registry\RedisConnectionRegistry;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;
use MonkeysLegion\Sockets\Registry\PhpRedisClient;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use Redis;
use RedisException;

/**
 * RedisRegistryIntegrationTest
 * 
 * Performs integration testing against a live Redis instance.
 */
final class RedisRegistryIntegrationTest extends TestCase
{
    private ?PhpRedisClient $client = null;
    private ?Redis $redis = null;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        $host = \getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (\getenv('REDIS_PORT') ?: 6379);

        try {
            $this->redis = new Redis();
            if (!@$this->redis->connect($host, $port, 0.5)) {
                $this->markTestSkipped('Redis server not available at ' . $host . ':' . $port);
            }
            // Clear test keys
            $this->redis->del($this->redis->keys('ml_sockets:*'));
            $this->client = new PhpRedisClient($this->redis);
        } catch (RedisException $e) {
            $this->markTestSkipped('Redis connection failed: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->redis) {
            $this->redis->del($this->redis->keys('ml_sockets:*'));
            $this->redis->close();
        }
    }

    #[Test]
    public function it_synchronizes_state_across_registry_instances(): void
    {
        $local1 = new ConnectionRegistry();
        $local2 = new ConnectionRegistry();

        $registry1 = new RedisConnectionRegistry($local1, $this->client);
        $registry2 = new RedisConnectionRegistry($local2, $this->client);

        $conn1 = $this->createStub(ConnectionInterface::class);
        $conn1->method('getId')->willReturn('client-A');

        $conn2 = $this->createStub(ConnectionInterface::class);
        $conn2->method('getId')->willReturn('client-B');

        // Node 1: Client A joins room 'lobby'
        $registry1->add($conn1);
        $registry1->tag($conn1, 'lobby');

        // Node 2: Client B joins room 'lobby'
        $registry2->add($conn2);
        $registry2->tag($conn2, 'lobby');

        // Verification:
        // Node 1 should see only Client A in lobby (locally) but Redis should have both IDs
        $lobbyNode1 = \iterator_to_array($registry1->getByTag('lobby'));
        $this->assertCount(1, $lobbyNode1);
        $this->assertSame('client-A', $lobbyNode1[0]->getId());

        // Low-level Redis check
        $allIds = $this->redis->sMembers('ml_sockets:tags:lobby');
        $this->assertCount(2, $allIds);
        $this->assertContains('client-A', $allIds);
        $this->assertContains('client-B', $allIds);
    }
}
