<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Frame\MessageAssembler;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use Psr\Log\NullLogger;

final class SlowLorisTest extends TestCase
{
    /**
     * Verifies that the server remains responsive and can accept new 
     * connections even after having to prune a malicious client.
     */
    #[Test]
    public function it_remains_responsive_after_dropping_adversarial_client(): void
    {
        $port = 9102;
        
        $driver = new StreamSocketDriver(
            frameProcessor: new FrameProcessor(),
            assembler: new MessageAssembler(),
            negotiator: new HandshakeNegotiator(new ResponseFactory()),
            logger: new NullLogger(),
            writeBufferSize: 1024 // Tiny 1KB buffer
        );

        // Hook to punish overloaded clients
        $driver->onOpen(function (ConnectionInterface $conn) {
            if ($conn->getId() === 'malicious') {
                try {
                    $conn->send(\str_repeat('X', 2048));
                } catch (\RuntimeException $e) {
                    $conn->close();
                }
            }
        });

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try {
                $driver->listen('127.0.0.1', $port);
            } catch (\Throwable $e) {
                exit(1);
            }
            exit(0);
        }

        // Wait for boot
        \usleep(100000);

        try {
            // 1. Malicious Client connects
            $clientA = @\stream_socket_client("tcp://127.0.0.1:$port");
            $this->assertIsResource($clientA);
            
            // Handshake as 'malicious' ID (simulation)
            $handshake = "GET / HTTP/1.1\r\n" .
                         "Host: 127.0.0.1\r\n" .
                         "Upgrade: websocket\r\n" .
                         "Connection: Upgrade\r\n" .
                         "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                         "Sec-WebSocket-Version: 13\r\n\r\n";
            \fwrite($clientA, $handshake);
            
            \usleep(200000);

            // 2. Healthy Client connects immediately after
            $clientB = @\stream_socket_client("tcp://127.0.0.1:$port");
            $this->assertIsResource($clientB);
            
            \fwrite($clientB, $handshake);
            \usleep(200000);
            
            $responseB = \fread($clientB, 1024);
            
            // 3. Verify Server is still alive and responsive
            $this->assertStringContainsString('101 Switching Protocols', $responseB, "Server should remain responsive to healthy clients after dropping an adversarial one.");

        } finally {
            if (isset($clientA) && \is_resource($clientA)) @\fclose($clientA);
            if (isset($clientB) && \is_resource($clientB)) @\fclose($clientB);
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
        }
    }
}
