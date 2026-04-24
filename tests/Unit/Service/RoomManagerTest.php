<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use MonkeysLegion\Sockets\Service\RoomManager;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Sockets\Contracts\ChannelAuthorizerInterface;

#[AllowMockObjectsWithoutExpectations]
final class RoomManagerTest extends TestCase
{
    private $registry;
    private $broadcaster;
    private $authorizer;
    private $roomManager;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->broadcaster = $this->createMock(BroadcasterInterface::class);
        $this->authorizer = $this->createMock(ChannelAuthorizerInterface::class);
        
        $this->roomManager = new RoomManager(
            $this->registry,
            $this->broadcaster,
            $this->authorizer
        );
    }

    #[Test]
    public function it_can_join_public_rooms(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('getId')->willReturn('conn_1');

        $this->registry->expects($this->once())
            ->method('tag')
            ->with($conn, 'public:lobby');

        $this->roomManager->joinPublic($conn, 'lobby');
    }

    #[Test]
    public function it_can_join_private_rooms_when_authorized(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('getId')->willReturn('conn_1');

        $this->authorizer->expects($this->once())
            ->method('authorize')
            ->with($conn, 'team-alpha', ['token' => 'secret'])
            ->willReturn(true);

        $this->registry->expects($this->once())
            ->method('tag')
            ->with($conn, 'private:team-alpha');

        $result = $this->roomManager->joinPrivate($conn, 'team-alpha', ['token' => 'secret']);
        $this->assertTrue($result);
    }

    #[Test]
    public function it_rejects_private_rooms_when_unauthorized(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $this->authorizer->method('authorize')->willReturn(false);

        $this->registry->expects($this->never())->method('tag');

        $result = $this->roomManager->joinPrivate($conn, 'denied-room');
        $this->assertFalse($result);
    }

    #[Test]
    public function it_handles_presence_channel_joins(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('getId')->willReturn('joiner');
        $conn->method('getMetadata')->willReturn(['user_info' => ['name' => 'Joiner']]);

        $other = $this->createMock(ConnectionInterface::class);
        $other->method('getId')->willReturn('other');
        $other->method('getMetadata')->willReturn(['user_info' => ['name' => 'Existing']]);

        // Auth passes
        $this->authorizer->method('authorize')->willReturn(true);

        // Registry returns existing members
        $this->registry->expects($this->once())->method('getByTag')
            ->with('private:presence:chat')
            ->willReturn([$conn, $other]);

        // Broadcaster notifies others
        $this->broadcaster->expects($this->once())
            ->method('to')
            ->with('private:presence:chat')
            ->willReturn($this->broadcaster);
        
        $this->broadcaster->expects($this->once())
            ->method('emit')
            ->with('presence:joined', $this->callback(fn($data) => 
                $data['room'] === 'chat' && $data['member']['id'] === 'joiner'
            ));

        $members = $this->roomManager->joinPresence($conn, 'chat');
        
        $this->assertCount(1, $members);
        $this->assertEquals('other', $members[0]['id']);
    }

    #[Test]
    public function it_notifies_on_leaving_presence_channels(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('getId')->willReturn('leaver');

        $this->registry->expects($this->once())
            ->method('untag')
            ->with($conn, 'private:presence:lobby');

        $this->broadcaster->expects($this->once())
            ->method('to')
            ->with('private:presence:lobby')
            ->willReturn($this->broadcaster);

        $this->broadcaster->expects($this->once())
            ->method('emit')
            ->with('presence:left', [
                'room' => 'lobby',
                'member_id' => 'leaver'
            ]);

        $this->roomManager->leave($conn, 'private:presence:lobby');
    }
}
