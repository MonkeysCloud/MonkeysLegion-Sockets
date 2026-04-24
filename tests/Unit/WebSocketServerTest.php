<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use MonkeysLegion\Sockets\Server\WebSocketServer;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Sockets\Contracts\FormatterInterface;

#[AllowMockObjectsWithoutExpectations]
final class WebSocketServerTest extends TestCase
{
    private $registry;
    private $broadcaster;
    private $formatter;
    private $server;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->broadcaster = $this->createMock(BroadcasterInterface::class);
        $this->formatter = $this->createMock(FormatterInterface::class);
        
        $this->server = new WebSocketServer(
            $this->registry,
            $this->broadcaster,
            $this->formatter
        );
    }

    #[Test]
    public function it_can_join_rooms(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        
        $this->registry->expects($this->once())
            ->method('tag')
            ->with($conn, 'room:lobby');

        $this->server->join($conn, 'lobby');
    }

    #[Test]
    public function it_can_leave_rooms(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        
        $this->registry->expects($this->once())
            ->method('untag')
            ->with($conn, 'room:chat');

        $this->server->leave($conn, 'chat');
    }

    #[Test]
    public function it_facilitates_broadcasting_to_rooms(): void
    {
        $this->broadcaster->expects($this->once())
            ->method('to')
            ->with('room:news')
            ->willReturn($this->broadcaster);

        $this->server->to('news');
    }

    #[Test]
    public function it_facilitates_direct_connection_broadcasting(): void
    {
        $this->broadcaster->expects($this->once())
            ->method('toConnection')
            ->with('id_123')
            ->willReturn($this->broadcaster);

        $this->server->toConnection('id_123');
    }

    #[Test]
    public function it_can_global_broadcast(): void
    {
        $this->broadcaster->expects($this->once())
            ->method('emit')
            ->with('global_event', ['bar' => 'baz']);

        $this->server->broadcast('global_event', ['bar' => 'baz']);
    }

    #[Test]
    public function it_can_join_public_channels(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('getId')->willReturn('c1');

        $this->registry->expects($this->once())
            ->method('tag')
            ->with($conn, 'public:lobby');

        $this->server->joinPublic($conn, 'lobby');
    }

    #[Test]
    public function it_can_join_private_channels_with_authorizer(): void
    {
        $authorizer = $this->createMock(\MonkeysLegion\Sockets\Contracts\ChannelAuthorizerInterface::class);
        $server = new WebSocketServer($this->registry, $this->broadcaster, $this->formatter, $authorizer);
        
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('getId')->willReturn('c1');

        $authorizer->expects($this->once())
            ->method('authorize')
            ->willReturn(true);

        $this->registry->expects($this->once())
            ->method('tag')
            ->with($conn, 'private:secret');

        $this->assertTrue($server->joinPrivate($conn, 'secret'));
    }

    #[Test]
    public function it_can_join_presence_channels(): void
    {
        $authorizer = $this->createMock(\MonkeysLegion\Sockets\Contracts\ChannelAuthorizerInterface::class);
        $server = new WebSocketServer($this->registry, $this->broadcaster, $this->formatter, $authorizer);
        
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('getId')->willReturn('c1');
        $conn->method('getMetadata')->willReturn([]);

        $authorizer->method('authorize')->willReturn(true);
        $this->registry->method('getByTag')->willReturn([$conn]);

        $result = $server->joinPresence($conn, 'chat');
        $this->assertIsArray($result);
    }

    #[Test]
    public function it_can_leave_semantic_channels(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('getId')->willReturn('c1');
        
        $tags = [];
        $this->registry->method('untag')
            ->willReturnCallback(function($c, $t) use (&$tags) {
                $tags[] = $t;
            });

        $this->server->leavePublic($conn, 'lobby');
        $this->server->leavePrivate($conn, 'secret');

        $this->assertContains('public:lobby', $tags);
        $this->assertContains('private:secret', $tags);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_provides_access_to_components(): void
    {
        $this->assertSame($this->registry, $this->server->getRegistry());
        $this->assertSame($this->formatter, $this->server->getFormatter());
    }
}
