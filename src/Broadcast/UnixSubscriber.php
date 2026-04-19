<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Broadcast;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * UnixSubscriber
 * 
 * Listens on a Unix Domain Socket and dispatches incoming messages 
 * to the BroadcastBridge. Ideal for single-server performance.
 */
class UnixSubscriber
{
    private bool $running = false;
    private $server = null;

    public function __construct(
        private readonly string $socketPath,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    /**
     * Start the listening loop.
     * Note: This is a BLOCKING operation.
     */
    public function listen(callable $handler): void
    {
        // Cleanup old socket file if it exists
        if (\file_exists($this->socketPath)) {
            @\unlink($this->socketPath);
        }

        $this->server = @\stream_socket_server('unix://' . $this->socketPath, $errno, $errstr);

        if (!$this->server) {
            throw new \RuntimeException("Could not create IPC socket server on {$this->socketPath}: $errstr");
        }

        // Set permissions to allow the web worker (e.g., www-data) to write to it
        @\chmod($this->socketPath, 0666);

        $this->running = true;
        $this->logger->info("Unix Subscriber listening on {$this->socketPath}");

        while ($this->running) {
            $conn = @\stream_socket_accept($this->server, 1);
            
            if ($conn) {
                try {
                    // Read payload until newline
                    $payload = \fgets($conn);
                    if ($payload) {
                        $handler(\trim($payload));
                    }
                } catch (Throwable $e) {
                    $this->logger->error("Error in Unix IPC handler: " . $e->getMessage());
                }
                @\fclose($conn);
            }
        }

        @\fclose($this->server);
        if (\file_exists($this->socketPath)) {
            @\unlink($this->socketPath);
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $this->logger->info("Stopping Unix Subscriber...");
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
