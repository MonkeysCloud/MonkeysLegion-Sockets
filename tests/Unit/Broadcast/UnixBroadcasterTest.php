<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Broadcast;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Broadcast\UnixBroadcaster;
use RuntimeException;

final class UnixBroadcasterTest extends TestCase
{
    private string $socketPath;

    protected function setUp(): void
    {
        $this->socketPath = \sys_get_temp_dir() . '/ml_unit_' . \uniqid() . '.sock';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->socketPath)) {
            @\unlink($this->socketPath);
        }
    }

    #[Test]
    public function it_throws_exception_if_socket_file_does_not_exist(): void
    {
        $broadcaster = new UnixBroadcaster('/non/existent/path.sock');
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('socket does not exist');
        
        $broadcaster->emit('test');
    }

    #[Test]
    public function it_can_target_connections_and_tags(): void
    {
        // Mock a socket server to accept the connection
        $server = \stream_socket_server('unix://' . $this->socketPath);
        
        $broadcaster = new UnixBroadcaster($this->socketPath);
        
        // 1. Target Tag
        $broadcaster->to('room1')->emit('event1');
        $conn = \stream_socket_accept($server);
        $payload = \json_decode(\trim(\fgets($conn)), true);
        $this->assertEquals('tag', $payload['type']);
        $this->assertEquals('room1', $payload['target']);
        \fclose($conn);

        // 2. Target Connection
        $broadcaster->toConnection('conn123')->emit('event2');
        $conn = \stream_socket_accept($server);
        $payload = \json_decode(\trim(\fgets($conn)), true);
        $this->assertEquals('connection', $payload['type']);
        $this->assertEquals('conn123', $payload['target']);
        \fclose($conn);

        // 3. Raw Broadast
        $broadcaster->broadcast('hello');
        $conn = \stream_socket_accept($server);
        $payload = \json_decode(\trim(\fgets($conn)), true);
        $this->assertEquals('raw', $payload['event']);
        $this->assertEquals('hello', $payload['data']);
        \fclose($conn);

        \fclose($server);
    }

    #[Test]
    public function it_supports_legacy_broadcast(): void
    {
        $server = \stream_socket_server('unix://' . $this->socketPath);
        $broadcaster = new UnixBroadcaster($this->socketPath);
        
        $broadcaster->broadcast('legacy');
        $conn = \stream_socket_accept($server);
        $payload = \json_decode(\trim(\fgets($conn)), true);
        $this->assertEquals('raw', $payload['event']);
        $this->assertEquals('legacy', $payload['data']);
        \fclose($conn);
        \fclose($server);
    }
}
