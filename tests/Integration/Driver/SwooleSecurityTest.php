<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Driver;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\SwooleDriver;

/**
 * SwooleSecurityTest
 * 
 * Verifies that the Swoole driver is resilient against common 
 * WebSocket adversarial patterns.
 */
final class SwooleSecurityTest extends TestCase
{
    #[Test]
    public function it_handles_swoole_fragmentation_lookups(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $port = 9022;
        $driver = new SwooleDriver();
        $tempFile = \sys_get_temp_dir() . '/ml_swoole_test.data';
        
        if (\file_exists($tempFile)) {
            \unlink($tempFile);
        }

        $driver->onMessage(function($conn, $msg) use ($tempFile) {
            \file_put_contents($tempFile, $msg->getPayload());
        });

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try {
                $driver->listen('127.0.0.1', $port);
            } catch (\Throwable) {
            }
            exit(0);
        }

        // Wait for Swoole
        \usleep(500000);

        $client = \stream_socket_client("tcp://127.0.0.1:$port");
        if (!$client) {
            \posix_kill($pid, SIGKILL);
            $this->fail("Could not connect to Swoole server");
        }

        // Handshake
        \fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        \fread($client, 1024);

        // Send fragments
        $payload1 = \chr(0x01) . \chr(0x04) . "part"; 
        $payload2 = \chr(0x80) . \chr(0x04) . "done"; 
        
        \fwrite($client, $payload1);
        \fwrite($client, $payload2);
        
        \usleep(200000);

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);

        $receivedPayload = \file_exists($tempFile) ? \file_get_contents($tempFile) : '';
        if (\file_exists($tempFile)) {
            \unlink($tempFile);
        }

        $this->assertEquals('partdone', $receivedPayload, "Swoole failed to reassemble fragmented frames!");
    }
}
