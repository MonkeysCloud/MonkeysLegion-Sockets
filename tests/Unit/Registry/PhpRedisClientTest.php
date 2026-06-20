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
        $redis = $this->createMock(Redis::class);
        
        // Mock connection attributes to verify reconnection details
        $redis->method('getHost')->willReturn('127.0.0.1');
        $redis->method('getPort')->willReturn(6379);
        $redis->method('getTimeout')->willReturn(2.5);
        $redis->method('getPersistentID')->willReturn('p1');
        $redis->method('getAuth')->willReturn('secret');
        $redis->method('getDBNum')->willReturn(2);

        // Reconnect flow
        $redis->expects($this->once())->method('close');
        $redis->expects($this->once())->method('pconnect')->with('127.0.0.1', 6379, 2.5, 'p1');
        $redis->expects($this->once())->method('auth')->with('secret');
        $redis->expects($this->once())->method('select')->with(2);

        // Normal delegation call
        $redis->expects($this->once())->method('del')->with('test-key')->willReturn(1);

        $client = new PhpRedisClient($redis);

        // Artificially modify the PID to simulate a process fork
        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('connPid');
        $prop->setAccessible(true);
        $prop->setValue($client, 999999); // Set to a dummy PID that differs from getmypid()

        $this->assertSame(1, $client->del('test-key'));
    }
}
