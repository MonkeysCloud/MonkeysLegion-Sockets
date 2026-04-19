<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Broadcast;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Broadcast\RedisBroadcaster;
use MonkeysLegion\Sockets\Broadcast\BroadcastBridge;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\MessageSerializerInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * RedisBroadcastIntegrationTest
 * 
 * Verifies the end-to-end loop:
 * Broadcaster (sender) -> Redis -> Bridge (receiver) -> Registry -> Connection.
 */
final class RedisBroadcastIntegrationTest extends TestCase
{
    #[Test]
    public function it_broadcasts_to_all_connections_via_the_bridge(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $registry = $this->createStub(ConnectionRegistryInterface::class);
        $serializer = $this->createStub(MessageSerializerInterface::class);
        $conn1 = $this->createMock(ConnectionInterface::class);
        $conn2 = $this->createMock(ConnectionInterface::class);

        $publishedPayload = '';
        $redis->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function($channel, $payload) use (&$publishedPayload) {
                $publishedPayload = $payload;
                return 1;
            });

        $serializer->method('serialize')->willReturn('SERIALIZED_DATA');
        $registry->method('all')->willReturn([$conn1, $conn2]);

        $conn1->expects($this->once())->method('send')->with('SERIALIZED_DATA');
        $conn2->expects($this->once())->method('send')->with('SERIALIZED_DATA');

        $broadcaster = new RedisBroadcaster($redis);
        $broadcaster->emit('test_event', ['hello' => 'world']);

        $bridge = new BroadcastBridge($registry, $serializer);
        $bridge->handle($publishedPayload);

        $envelope = \json_decode($publishedPayload, true);
        $this->assertEquals('broadcast', $envelope['type']);
    }

    #[Test]
    public function it_targets_specific_tags_via_the_bridge(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $serializer = $this->createStub(MessageSerializerInterface::class);
        $taggedConn = $this->createMock(ConnectionInterface::class);

        $publishedPayload = '';
        $redis->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function($ch, $payload) use (&$publishedPayload) {
                $publishedPayload = $payload;
                return 1;
            });

        $serializer->method('serialize')->willReturn('TAGGED_DATA');

        $registry->expects($this->once())
            ->method('getByTag')
            ->with('room-101')
            ->willReturn([$taggedConn]);

        $taggedConn->expects($this->once())->method('send')->with('TAGGED_DATA');

        $broadcaster = new RedisBroadcaster($redis);
        $broadcaster->to('room-101')->emit('alert', ['msg' => 'fire']);

        $bridge = new BroadcastBridge($registry, $serializer);
        $bridge->handle($publishedPayload);
    }

    #[Test]
    public function it_targets_specific_connection_ids(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $serializer = $this->createStub(MessageSerializerInterface::class);
        $conn = $this->createMock(ConnectionInterface::class);

        $publishedPayload = '';
        $redis->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function($ch, $payload) use (&$publishedPayload) {
                $publishedPayload = $payload;
                return 1;
            });

        $serializer->method('serialize')->willReturn('DIRECT_DATA');

        $registry->expects($this->once())
            ->method('get')
            ->with('conn-456')
            ->willReturn($conn);

        $conn->expects($this->once())->method('send')->with('DIRECT_DATA');

        $broadcaster = new RedisBroadcaster($redis);
        $broadcaster->toConnection('conn-456')->emit('direct');

        $bridge = new BroadcastBridge($registry, $serializer);
        $bridge->handle($publishedPayload);
    }

    #[Test]
    public function it_bypasses_serializer_for_raw_broadcasts(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $registry = $this->createStub(ConnectionRegistryInterface::class);
        $serializer = $this->createMock(MessageSerializerInterface::class);
        $conn = $this->createMock(ConnectionInterface::class);

        $publishedPayload = '';
        $redis->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function($ch, $payload) use (&$publishedPayload) {
                $publishedPayload = $payload;
                return 1;
            });

        // Serializer should NOT be called
        $serializer->expects($this->never())->method('serialize');
        $registry->method('all')->willReturn([$conn]);
        $conn->expects($this->once())->method('send')->with('BONE_RAW_BYTES');

        $broadcaster = new RedisBroadcaster($redis);
        $broadcaster->raw('BONE_RAW_BYTES');

        $bridge = new BroadcastBridge($registry, $serializer);
        $bridge->handle($publishedPayload);
    }

    #[Test]
    public function it_silently_fails_on_malformed_json_payloads(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $bridge = new BroadcastBridge(
            $this->createStub(ConnectionRegistryInterface::class),
            $this->createStub(MessageSerializerInterface::class),
            $logger
        );

        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('BroadcastBridge failed to process payload'));

        $bridge->handle('INVALID_JSON{}}');
    }
}
