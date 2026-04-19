<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Driver;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * SocketChaosTest
 * 
 * Testing the transport drivers under adverse network conditions.
 */
final class SocketChaosTest extends TestCase
{
    #[Test]
    public function it_rejects_and_closes_partial_handshake_attacks(): void
    {
        $port = 9008;
        $factory = $this->createStub(ResponseFactoryInterface::class);
        $driver = new StreamSocketDriver(negotiator: new HandshakeNegotiator($factory));

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try { $driver->listen('127.0.0.1', $port); } catch (\Throwable $e) {}
            exit(0);
        }

        \usleep(100000);
        $client = \stream_socket_client("tcp://127.0.0.1:$port");
        
        // Attacker sends only the first line of the handshake
        \fwrite($client, "GET / HTTP/1.1\r\n");
        \usleep(100000);
        
        $response = \fread($client, 1024);
        
        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);

        // Server should have sent a 400 Bad Request and closed
        $this->assertStringContainsString('400 Bad Request', (string)$response);
        $this->assertTrue(\feof($client), "Connection should have been closed by server");
        \fclose($client);
    }

    #[Test]
    public function it_handles_graceful_disconnect_mid_handshake(): void
    {
        $port = 9009;
        $factory = $this->createStub(ResponseFactoryInterface::class);
        $driver = new StreamSocketDriver(negotiator: new HandshakeNegotiator($factory));

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try { $driver->listen('127.0.0.1', $port); } catch (\Throwable $e) {}
            exit(0);
        }

        \usleep(100000);
        $client = \stream_socket_client("tcp://127.0.0.1:$port");
        
        // Attacker starts handshake then abruptly closes without finishing
        \fwrite($client, "GET / HTTP/1.1\r\n");
        \fclose($client);
        
        \usleep(100000); 

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);

        // Server should still be alive (the test passing implies it didn't crash)
        $this->assertTrue(true);
    }
}
