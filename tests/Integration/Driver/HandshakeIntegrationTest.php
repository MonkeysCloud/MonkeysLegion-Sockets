<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Driver;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Frame\MessageAssembler;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HandshakeIntegrationTest
 * 
 * Ensures the StreamSocketDriver correctly utilizes the HandshakeNegotiator
 * to validate incoming connections.
 */
final class HandshakeIntegrationTest extends TestCase
{
    #[Test]
    public function it_successfully_opens_connection_with_valid_handshake(): void
    {
        $port = 9005;
        
        // Setup Negotiator with Mock Factory
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(101);
        $response->method('getProtocolVersion')->willReturn('1.1');
        $response->method('getReasonPhrase')->willReturn('Switching Protocols');
        $response->method('getHeaders')->willReturn([
            'Upgrade' => ['websocket'],
            'Connection' => ['Upgrade'],
            'Sec-WebSocket-Accept' => ['s3pPLMBiTxaQ9kYGzzhZRbK+xOo=']
        ]);
        $response->method('withHeader')->willReturn($response);

        $factory = $this->createStub(ResponseFactoryInterface::class);
        $factory->method('createResponse')->willReturn($response);
        
        $negotiator = new HandshakeNegotiator($factory);
        $driver = new StreamSocketDriver(
            frameProcessor: new FrameProcessor(),
            assembler: new MessageAssembler(),
            negotiator: $negotiator
        );

        // Parallel execution logic
        $pid = \pcntl_fork();
        if ($pid === 0) {
            try { 
                $driver->listen('127.0.0.1', $port); 
            } catch (\Throwable $e) {
                \file_put_contents('test_error.log', $e->getMessage());
                exit(1);
            }
            exit(0);
        }

        \usleep(100000); // More time for server to bind
        
        $client = @\stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 1);
        
        if (!$client) {
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
            $this->fail("Could not connect to test server: $errstr");
        }

        $handshake = "GET / HTTP/1.1\r\n" .
                     "Host: localhost\r\n" .
                     "Upgrade: websocket\r\n" .
                     "Connection: Upgrade\r\n" .
                     "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                     "Sec-WebSocket-Version: 13\r\n\r\n";
        
        \fwrite($client, $handshake);
        \usleep(100000);
        
        $buffer = \fread($client, 2048);
        
        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);

        $this->assertIsString($buffer, "Server did not send any data");
        $this->assertStringContainsString('101 Switching Protocols', $buffer);
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $buffer);
        \fclose($client);
    }

    #[Test]
    public function it_closes_connection_on_invalid_handshake(): void
    {
        $port = 9006;
        
        $factory = $this->createStub(ResponseFactoryInterface::class);
        $negotiator = new HandshakeNegotiator($factory);
        $driver = new StreamSocketDriver(
            frameProcessor: new FrameProcessor(),
            assembler: new MessageAssembler(),
            negotiator: $negotiator
        );

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try { $driver->listen('127.0.0.1', $port); } catch (\Throwable $e) { exit(1); }
            exit(0);
        }

        \usleep(100000);
        
        $client = @\stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 1);
        if (!$client) {
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
            $this->fail("Could not connect to test server: $errstr");
        }

        // Bad handshake (Missing key)
        $handshake = "GET / HTTP/1.1\r\n" .
                     "Upgrade: websocket\r\n\r\n";
        
        \fwrite($client, $handshake);
        \usleep(100000);
        
        $response = \fread($client, 1024);
        
        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);

        $this->assertStringNotContainsString('101 Switching Protocols', (string)$response);
        \fclose($client);
    }
}
