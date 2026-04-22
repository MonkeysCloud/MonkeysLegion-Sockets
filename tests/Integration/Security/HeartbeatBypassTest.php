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
use Psr\Log\AbstractLogger;

final class HeartbeatBypassTest extends TestCase
{
    /**
     * Infinite Idle Bypass Attack.
     */
    #[Test]
    public function it_closes_connections_sending_only_junk_data(): void
    {
        $port = 9101;
        $signalFile = 'tests/diagnostics/reap_bypass.signal';
        @\unlink($signalFile);
        
        // We use a Signal Logger to detect reaping without polluting production code
        $logger = new class($signalFile) extends AbstractLogger {
            public function __construct(private string $file) {}
            public function log($level, \Stringable|string $message, array $context = []): void {
                if (\str_contains((string)$message, 'Reaping zombie')) {
                    \file_put_contents($this->file, 'REAPED');
                }
            }
        };

        $driver = new StreamSocketDriver(
            frameProcessor: new FrameProcessor(),
            assembler: new MessageAssembler(),
            negotiator: new HandshakeNegotiator(new ResponseFactory()),
            logger: $logger,
            heartbeatInterval: 2
        );

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try {
                $driver->listen('127.0.0.1', $port);
            } catch (\Throwable $e) {
                exit(1);
            }
            exit(0);
        }

        \usleep(500000);

        try {
            $client = @\stream_socket_client("tcp://127.0.0.1:$port");
            $this->assertIsResource($client, "Could not connect to server");

            $handshake = "GET / HTTP/1.1\r\n" .
                         "Host: 127.0.0.1\r\n" .
                         "Upgrade: websocket\r\n" .
                         "Connection: Upgrade\r\n" .
                         "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                         "Sec-WebSocket-Version: 13\r\n\r\n";
            \fwrite($client, $handshake);
            \fread($client, 1024);

            // Send junk for 3 seconds
            for ($i = 0; $i < 3; $i++) {
                @\fwrite($client, "\x00\x00");
                \sleep(1);
            }

            // Wait until the reaper interval definitely passes (4s threshold)
            \sleep(5);

            // Verify via the Signal Logger that the server detected and reaped the connection
            $this->assertFileExists($signalFile, "Server logic should have detected and reaped the connection despite junk data.");

        } finally {
            if (isset($client) && \is_resource($client)) @\fclose($client);
            @\unlink($signalFile);
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
        }
    }
}
