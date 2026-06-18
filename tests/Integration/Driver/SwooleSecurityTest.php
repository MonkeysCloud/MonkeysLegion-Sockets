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

    #[Test]
    public function it_refuses_connection_on_invalid_handshake(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $port = 9023;

        // Setup HandshakeNegotiator with AllowedOriginsMiddleware
        $responseFactory = new \MonkeysLegion\Sockets\Handshake\ResponseFactory();
        $pipeline = new \MonkeysLegion\Sockets\Handshake\MiddlewarePipeline();
        $pipeline->add(new \MonkeysLegion\Sockets\Handshake\AllowedOriginsMiddleware(
            ['http://allowed.com'],
            $responseFactory
        ));

        $negotiator = new \MonkeysLegion\Sockets\Handshake\HandshakeNegotiator(
            $responseFactory,
            null,
            $pipeline
        );

        $driver = new SwooleDriver(
            negotiator: $negotiator
        );

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try {
                $driver->listen('127.0.0.1', $port);
            } catch (\Throwable) {
            }
            exit(0);
        }

        // Wait for Swoole to boot
        \usleep(500000);

        $client = \stream_socket_client("tcp://127.0.0.1:$port");
        if (!$client) {
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
            $this->fail("Could not connect to Swoole server");
        }

        // Send handshake from unauthorized origin
        $request = "GET / HTTP/1.1\r\n" .
                   "Host: localhost\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                   "Origin: http://unauthorized.com\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";

        \fwrite($client, $request);
        \usleep(200000);

        $response = \fread($client, 2048);

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);
        \fclose($client);

        $this->assertIsString($response);
        $this->assertStringContainsString('403 Forbidden', $response, "Swoole driver accepted connection from unauthorized origin!");
        $this->assertStringNotContainsString('101 Switching Protocols', $response);
    }

    #[Test]
    public function it_refuses_connection_without_token(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $port = 9024;

        $responseFactory = new \MonkeysLegion\Sockets\Handshake\ResponseFactory();
        $authenticator = new \MonkeysLegion\Sockets\Handshake\QueryTokenAuthenticator('secret123');

        $negotiator = new \MonkeysLegion\Sockets\Handshake\HandshakeNegotiator(
            $responseFactory,
            $authenticator
        );

        $driver = new SwooleDriver(
            negotiator: $negotiator
        );

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try {
                $driver->listen('127.0.0.1', $port);
            } catch (\Throwable) {
            }
            exit(0);
        }

        \usleep(500000);

        $client = \stream_socket_client("tcp://127.0.0.1:$port");
        if (!$client) {
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
            $this->fail("Could not connect to Swoole server");
        }

        // Send handshake without token or with bad token
        $request = "GET /?token=wrong HTTP/1.1\r\n" .
                   "Host: localhost\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";

        \fwrite($client, $request);
        \usleep(200000);

        $response = \fread($client, 2048);

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);
        \fclose($client);

        $this->assertIsString($response);
        $this->assertStringNotContainsString('101 Switching Protocols', $response);
    }

    #[Test]
    public function it_accepts_valid_handshake(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $port = 9025;

        $responseFactory = new \MonkeysLegion\Sockets\Handshake\ResponseFactory();
        $pipeline = new \MonkeysLegion\Sockets\Handshake\MiddlewarePipeline();
        $pipeline->add(new \MonkeysLegion\Sockets\Handshake\AllowedOriginsMiddleware(
            ['http://allowed.com'],
            $responseFactory
        ));

        $authenticator = new \MonkeysLegion\Sockets\Handshake\QueryTokenAuthenticator('secret123');

        $negotiator = new \MonkeysLegion\Sockets\Handshake\HandshakeNegotiator(
            $responseFactory,
            $authenticator,
            $pipeline
        );

        $driver = new SwooleDriver(
            negotiator: $negotiator
        );

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try {
                $driver->listen('127.0.0.1', $port);
            } catch (\Throwable) {
            }
            exit(0);
        }

        \usleep(500000);

        $client = \stream_socket_client("tcp://127.0.0.1:$port");
        if (!$client) {
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
            $this->fail("Could not connect to Swoole server");
        }

        // Send valid handshake (allowed origin + correct token)
        $request = "GET /?token=secret123 HTTP/1.1\r\n" .
                   "Host: localhost\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                   "Origin: http://allowed.com\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";

        \fwrite($client, $request);
        \usleep(200000);

        $response = \fread($client, 2048);

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);
        \fclose($client);

        $this->assertIsString($response);
        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertStringContainsStringIgnoringCase('Sec-WebSocket-Accept', $response);
    }
}
