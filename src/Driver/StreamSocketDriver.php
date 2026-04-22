<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Frame\MessageAssembler;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use MonkeysLegion\Sockets\Handshake\RequestParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * StreamSocketDriver
 * 
 * Native PHP implementation of a WebSocket transport driver using
 * `stream_socket_server`. Handles the multi-connection event loop
 * via `stream_select`.
 * 
 * Optimized for high-concurrency with non-blocking write buffering.
 */
final class StreamSocketDriver implements DriverInterface
{
    /** @var resource|null The main listening server socket */
    private mixed $server = null;

    /** @var array<int, StreamConnection> Active connection objects indexed by stream ID */
    private array $connections = [];

    /** @var array<int, resource> Raw stream resources for the select loop */
    private array $streams = [];

    /** @var array<int, bool> Tracks if a connection has completed the handshake */
    private array $handshaked = [];

    /** @var array<string, callable(mixed...): void> Event callbacks */
    private array $callbacks = [];

    /** @var bool Loop state control */
    private bool $running = false;

    /** @var array<int, string> Input buffers */
    private array $buffers = [];

    /** @var int Timestamp of the last heartbeat cycle */
    private int $lastHeartbeatCycle = 0;

    /** @var \MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface|null */
    private ?\MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface $registry = null;

    public function __construct(
        private readonly FrameProcessor $frameProcessor = new FrameProcessor(),
        private readonly MessageAssembler $assembler = new MessageAssembler(),
        private readonly HandshakeNegotiator $negotiator = new HandshakeNegotiator(new ResponseFactory()),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $writeBufferSize = 5242880, // Default 5MB
        private readonly int $heartbeatInterval = 60      // Default 60 seconds
    ) {
        $this->lastHeartbeatCycle = \time();
    }

    public function setRegistry(\MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Start the socket server and enter the event loop.
     */
    public function listen(string $address, int $port, array $context = []): void
    {
        $uri = \sprintf('tcp://%s:%d', $address, $port);
        $ctx = \stream_context_create($context);

        $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);

        if (!\is_resource($server)) {
            throw new \RuntimeException(\sprintf('Could not bind to %s: %s', $uri, $errstr));
        }

        $this->server = $server;
        $this->logger->info("WebSocket server listening on {$uri}");
        
        $this->running = true;
        $this->streams[(int) $this->server] = $this->server;

        $this->loop();
    }

    /**
     * Stop the loop and close all connections gracefully.
     */
    public function stop(): void
    {
        $this->running = false;
        
        $this->logger->info("Shutting down WebSocket server gracefully...");
        
        // Send 1001 Going Away to all clients
        foreach ($this->connections as $id => $connection) {
            try {
                $connection->close(1001, 'Server shutting down');
            } catch (Throwable) {
                // Ignore errors during mass close
            }
        }

        if (\is_resource($this->server)) {
            @\fclose($this->server);
        }
    }

    /**
     * The heart of the server: multiplexing multiple I/O streams.
     */
    private function loop(): void
    {
        while ($this->running) {
            $read = $this->streams;
            $write = [];
            $except = null;

            // 1. Monitor connections with pending data for writability
            foreach ($this->connections as $id => $connection) {
                if ($connection->hasPendingWrites()) {
                    $write[] = $this->streams[$id];
                }
            }

            // 2. Perform the non-blocking select
            if (@\stream_select($read, $write, $except, 1) === false) {
                break;
            }

            // 3. Periodic Heartbeat System
            $this->processHeartbeats();

            // 4. Handle writable sockets (Clear buffers)
            if ($write) {
                foreach ($write as $stream) {
                    $id = (int) $stream;
                    $this->connections[$id]?->flush();
                }
            }

            // 5. Handle readable sockets (Accept or Read data)
            foreach ($read as $stream) {
                if ($stream === $this->server) {
                    $this->acceptConnection();
                } else {
                    $this->handleData($stream);
                }
            }
        }
    }

    /**
     * Active heartbeat system: sends Pings and reaps non-responding clients.
     */
    private function processHeartbeats(): void
    {
        $now = \time();
        
        // We run the cycle check every heartbeatInterval
        if ($now - $this->lastHeartbeatCycle < $this->heartbeatInterval) {
            return;
        }

        foreach ($this->connections as $id => $connection) {
            $idleTime = $now - $connection->lastActivity();

            // If idle for more than the limit, reap
            if ($idleTime >= ($this->heartbeatInterval * 2)) {
                $this->logger->info("Reaping zombie connection {$connection->getId()} (No Pong received)");
                $this->closeConnection($id);
                continue;
            }

            // If idle for more than one interval, send a proactive Ping
            if ($this->handshaked[$id]) {
                $connection->ping();
            }
        }

        $this->lastHeartbeatCycle = $now;
    }

    /**
     * Accept a new incoming client connection.
     */
    private function acceptConnection(): void
    {
        if (!\is_resource($this->server)) {
            return;
        }

        $socket = @\stream_socket_accept($this->server);
        if (!\is_resource($socket)) {
            return;
        }

        \stream_set_blocking($socket, false);
        
        $name = \stream_socket_get_name($socket, true);
        $id = \is_string($name) ? $name : (string) (int) $socket;
        $streamId = (int) $socket;

        $connection = new StreamConnection(
            resource: $socket, 
            id: $id, 
            frameProcessor: $this->frameProcessor,
            maxWriteBuffer: $this->writeBufferSize
        );
        
        $this->streams[$streamId] = $socket;
        $this->connections[$streamId] = $connection;
        $this->handshaked[$streamId] = false;
    }

    /**
     * Process data arriving on an existing socket.
     */
    private function handleData($stream): void
    {
        $streamId = (int) $stream;
        $connection = $this->connections[$streamId] ?? null;
        
        if (!$connection) {
            return;
        }

        $data = @\fread($stream, 8192);

        if ($data === '' || $data === false) {
            $this->closeConnection($streamId);
            return;
        }

        $this->buffers[$streamId] ??= '';
        $this->buffers[$streamId] .= $data;

        try {
            if (!$this->handshaked[$streamId]) {
                $pos = \strpos($this->buffers[$streamId], "\r\n\r\n");
                if ($pos !== false) {
                    $handshakeData = \substr($this->buffers[$streamId], 0, $pos + 4);
                    $this->buffers[$streamId] = \substr($this->buffers[$streamId], $pos + 4);
                    
                    $this->performHandshake($streamId, $handshakeData);
                }
            }

            if ($this->handshaked[$streamId]) {
                while (isset($this->buffers[$streamId]) && \strlen($this->buffers[$streamId]) >= 2) {
                    $frame = $this->frameProcessor->decode($this->buffers[$streamId]);
                    if (!$frame) {
                        break;
                    }

                    // Reset activity timer only on MEANINGFUL frames.
                    // We ignore Opcode 0 (Continuation) because it's used in 
                    // 'Infinite Idle' bypass attacks.
                    if ($frame->getOpcode() !== 0x0) {
                        $connection->touch();
                    }

                    $this->buffers[$streamId] = \substr($this->buffers[$streamId], $frame->getConsumedLength());

                    // Handle WebSocket Protocol Opcodes
                    switch ($frame->getOpcode()) {
                        case 0x8: // Close
                            $this->closeConnection($streamId);
                            return;
                        
                        case 0x9: // Ping -> Respond with Pong
                            $pong = $this->frameProcessor->encode($frame->getPayload(), 0xA);
                            @\fwrite($stream, $pong);
                            break;

                        case 0xA: // Pong -> Activity already touched above
                            break;

                        case 0x0: // Continuation
                        case 0x1: // Text
                        case 0x2: // Binary
                            $assembled = $this->assembler->assemble($streamId, $frame);
                            if ($assembled && isset($this->callbacks['message'])) {
                                ($this->callbacks['message'])($connection, $assembled);
                            }
                            break;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logger->error("Error handling data from {$connection->getId()}: {$e->getMessage()}");
            if (isset($this->callbacks['error'])) {
                ($this->callbacks['error'])($connection, $e);
            }
            $this->closeConnection($streamId);
        }
    }

    /**
     * Transition a connection from HTTP to WebSocket.
     */
    private function performHandshake(int $streamId, string $data): void
    {
        try {
            if ($this->negotiator) {
                $request = RequestParser::parse($data);
                $response = $this->negotiator->negotiate($request);
                
                $header = \sprintf(
                    "HTTP/%s %d %s\r\n",
                    $response->getProtocolVersion(),
                    $response->getStatusCode(),
                    $response->getReasonPhrase()
                );

                foreach ($response->getHeaders() as $name => $values) {
                    $header .= \sprintf("%s: %s\r\n", $name, \implode(', ', $values));
                }
                $header .= "\r\n";

                @\fwrite($this->streams[$streamId], $header);
            }

            $this->handshaked[$streamId] = true;
            
            $connection = $this->connections[$streamId] ?? null;
            if ($connection) {
                $connection->touch();
                if ($this->registry) {
                    $this->registry->add($connection);
                }
                if (isset($this->callbacks['open'])) {
                    ($this->callbacks['open'])($connection);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error("Handshake/Upgrade failed: " . $e->getMessage());
            
            // Only send 400 if we haven't already switched protocols
            if (!($this->handshaked[$streamId] ?? false)) {
                $errorResponse = "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n";
                @\fwrite($this->streams[$streamId], $errorResponse);
            }
            
            $this->closeConnection($streamId);
        }
    }

    /**
     * Cleanly remove a connection from the loop and close the stream.
     */
    private function closeConnection(int $streamId): void
    {
        $connection = $this->connections[$streamId] ?? null;
        if ($connection) {
            if ($this->registry) {
                $this->registry->remove($connection);
            }
            if (isset($this->callbacks['close'])) {
                ($this->callbacks['close'])($connection);
            }
        }

        $stream = $this->streams[$streamId] ?? null;
        unset($this->streams[$streamId], $this->connections[$streamId], $this->handshaked[$streamId], $this->buffers[$streamId]);
        
        if (\is_resource($stream)) {
            @\fclose($stream);
        }
    }

    public function onOpen(callable $callback): void
    {
        $this->callbacks['open'] = $callback;
    }

    public function onMessage(callable $callback): void
    {
        $this->callbacks['message'] = $callback;
    }

    public function onClose(callable $callback): void
    {
        $this->callbacks['close'] = $callback;
    }

    public function onError(callable $callback): void
    {
        $this->callbacks['error'] = $callback;
    }

    public function getHeartbeatInterval(): int
    {
        return $this->heartbeatInterval;
    }

    public function getWriteBufferSize(): int
    {
        return $this->writeBufferSize;
    }
}
