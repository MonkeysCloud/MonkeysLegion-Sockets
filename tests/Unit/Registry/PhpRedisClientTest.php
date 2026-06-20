<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Registry\PhpRedisClient;
use Redis;
use ReflectionClass;

#[CoversClass(PhpRedisClient::class)]
final class PhpRedisClientTest extends TestCase
{
    #[Test]
    public function it_delegates_sadd_to_redis_instance(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('sAdd')
            ->with('key1', 'value1')
            ->willReturn(1);

        $client = new PhpRedisClient($redis);
        $this->assertSame(1, $client->sAdd('key1', 'value1'));
    }

    #[Test]
    public function it_delegates_srem_to_redis_instance(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('sRem')
            ->with('key1', 'value1')
            ->willReturn(1);

        $client = new PhpRedisClient($redis);
        $this->assertSame(1, $client->sRem('key1', 'value1'));
    }

    #[Test]
    public function it_delegates_smembers_to_redis_instance(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('sMembers')
            ->with('key1')
            ->willReturn(['val1', 'val2']);

        $client = new PhpRedisClient($redis);
        $this->assertSame(['val1', 'val2'], $client->sMembers('key1'));
    }

    #[Test]
    public function it_delegates_del_to_redis_instance(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('del')
            ->with('key1')
            ->willReturn(1);

        $client = new PhpRedisClient($redis);
        $this->assertSame(1, $client->del('key1'));
    }

    #[Test]
    public function it_delegates_publish_to_redis_instance(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('publish')
            ->with('channel1', 'message1')
            ->willReturn(1);

        $client = new PhpRedisClient($redis);
        $this->assertSame(1, $client->publish('channel1', 'message1'));
    }

    #[Test]
    public function it_delegates_subscribe_to_redis_instance(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('subscribe')
            ->with(['channel1'], $this->isInstanceOf(\Closure::class));

        $client = new PhpRedisClient($redis);
        $client->subscribe(['channel1'], function() {});
    }

    #[Test]
    public function it_detects_pid_change_and_reconnects(): void
    {
        $oldRedis = $this->createStub(Redis::class);
        $newRedis = $this->createMock(Redis::class);

        // Mock connection attributes on the old instance to verify transferring details
        $oldRedis->method('getHost')->willReturn('127.0.0.1');
        $oldRedis->method('getPort')->willReturn(6379);
        $oldRedis->method('getTimeout')->willReturn(2.5);
        $oldRedis->method('getPersistentID')->willReturn('p1');
        $oldRedis->method('getAuth')->willReturn('secret');
        $oldRedis->method('getDBNum')->willReturn(2);

        // Reconnect flow must happen on the NEW instance
        $newRedis->expects($this->once())->method('pconnect')->with('127.0.0.1', 6379, 2.5, 'p1');
        $newRedis->expects($this->once())->method('auth')->with('secret');
        $newRedis->expects($this->once())->method('select')->with(2);

        // Normal delegation call on the new instance
        $newRedis->expects($this->once())->method('del')->with('test-key')->willReturn(1);

        $client = new PhpRedisClient($oldRedis, function() use ($newRedis) {
            return $newRedis;
        });

        // Artificially modify the PID to simulate a process fork
        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('connPid');
        $prop->setAccessible(true);
        $prop->setValue($client, 999999); // Set to a dummy PID that differs from getmypid()

        $this->assertSame(1, $client->del('test-key'));
    }

    #[Test]
    public function it_does_not_mutate_or_close_shared_redis_reference_in_other_clients_on_fork(): void
    {
        $sharedRedis = $this->createMock(Redis::class);
        $sharedRedis->method('getHost')->willReturn('127.0.0.1');
        $sharedRedis->method('getPort')->willReturn(6379);

        // The shared Redis instance must NEVER receive a close call because client1 instantiates a new one
        $sharedRedis->expects($this->never())->method('close');

        $newRedisForClient1 = $this->createMock(Redis::class);
        $newRedisForClient1->expects($this->once())->method('del')->with('test-key')->willReturn(1);

        $client1 = new PhpRedisClient($sharedRedis, function() use ($newRedisForClient1) {
            return $newRedisForClient1;
        });
        $client2 = new PhpRedisClient($sharedRedis);

        // Simulate fork on client1 only
        $ref = new ReflectionClass($client1);
        $prop = $ref->getProperty('connPid');
        $prop->setValue($client1, 999999);

        // Run command on client1. It should reconnect using factory without affecting the shared object.
        $this->assertSame(1, $client1->del('test-key'));
    }
}
