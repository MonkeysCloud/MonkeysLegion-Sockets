<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Driver;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\ReactSocketDriver;

/**
 * ReactSocketSecurityTest
 * 
 * Porting the adversarial suite to the ReactPHP driver.
 */
final class ReactSocketSecurityTest extends TestCase
{
    #[Test]
    public function it_handles_fragmentation_bombs_safely(): void
    {
        $port = 9055;
        $driver = new ReactSocketDriver();
        $tempFile = \sys_get_temp_dir() . '/ml_react_test.data';
        if (\file_exists($tempFile)) \unlink($tempFile);

        $driver->onOpen(function($conn) {
            \file_put_contents(\sys_get_temp_dir() . '/ml_react_open.log', 'OPEN');
        });

        $driver->onMessage(function($conn, $msg) use ($tempFile) {
            \file_put_contents($tempFile, $msg->getPayload());
        });

        // ReactPHP requires the loop to run
        $pid = \pcntl_fork();
        if ($pid === 0) {
            try { 
                $driver->listen('127.0.0.1', $port);
                \React\EventLoop\Loop::run();
            } catch (\Throwable $e) {}
            exit(0);
        }

        \usleep(500000);
        $client = \stream_socket_client("tcp://127.0.0.1:$port");
        
        // Handshake
        \fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        \fread($client, 1024);
        
        // Send Fragments
        $payload1 = \chr(0x01) . \chr(0x04) . "reac"; 
        $payload2 = \chr(0x80) . \chr(0x04) . "tphp"; 
        
        \fwrite($client, $payload1);
        \fwrite($client, $payload2);
        
        // Poll for result for up to 2 seconds
        $receivedPayload = '';
        for ($i = 0; $i < 20; $i++) {
            if (\file_exists($tempFile)) {
                $receivedPayload = \file_get_contents($tempFile);
                if ($receivedPayload === 'reactphp') break;
            }
            \usleep(100000);
        }

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);

        if (\file_exists($tempFile)) \unlink($tempFile);

        $this->assertEquals('reactphp', $receivedPayload, "Payload mismatch or timeout! Received: '$receivedPayload'");
    }
}
