<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * StreamSocketDriver
 * 
 * Native PHP implementation of a WebSocket transport driver using
 * `stream_socket_server`. Handles the multi-connection event loop
 * via `stream_select`.
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

    public function __construct(
        private readonly FrameProcessor $frameProcessor = new FrameProcessor(),
        private readonly ?HandshakeNegotiator $negotiator = null,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    /**
     * Start the socket server and enter the event loop.
     */
    public function listen(string $address, int $port, array $context = []): void
    {
        $uri = \sprintf('tcp://%s:%d', $address, $port);
        $ctx = \stream_context_create($context);

        // 1. Create the server socket. This is non-blocking and ready for connections.
        $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);

        if (!\is_resource($server)) {
            throw new \RuntimeException(\sprintf('Could not bind to %s: %s', $uri, $errstr));
        }

        $this->server = $server;
        $this->logger->info("WebSocket server listening on {$uri}");
        
        $this->running = true;
        
        // 2. Register the server socket in our watch list
        $this->streams[(int) $this->server] = $this->server;

        // 3. Start the infinite event loop
        $this->loop();
    }

    /**
     * Stop the loop and close all connections.
     */
    public function stop(): void
    {
        $this->running = false;
        foreach ($this->connections as $connection) {
            $connection->close();
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
            // We use a copy of the stream list because stream_select modifies it
            $read = $this->streams;
            $write = $except = null;

            // Wait until some stream has data or a new connection arrives
            if (@\stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $stream) {
                // If the signal is on the server socket, it's a new connection
                if ($stream === $this->server) {
                    $this->acceptConnection();
                } else {
                    // Otherwise, it's data from an existing client
                    $this->handleData($stream);
                }
            }
        }
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

        // Set to non-blocking to prevent fread from hanging the loop
        \stream_set_blocking($socket, false);
        
        $name = \stream_socket_get_name($socket, true);
        $id = \is_string($name) ? $name : (string) (int) $socket;
        $streamId = (int) $socket;

        // Create the internal connection wrapper
        $connection = new StreamConnection($socket, $id, $this->frameProcessor);
        
        $this->streams[$streamId] = $socket;
        $this->connections[$streamId] = $connection;
        $this->handshaked[$streamId] = false;
    }

    /**
     * Process data arriving on an existing socket.
     */
    private function handleData(mixed $stream): void
    {
        if (!\is_resource($stream)) {
            return;
        }

        $streamId = (int) $stream;
        $connection = $this->connections[$streamId] ?? null;
        
        if (!$connection) {
            return;
        }

        // Read the binary chunk
        $data = @\fread($stream, 8192);

        // Check for disconnect signals (empty data usually means EOF)
        if ($data === '' || $data === false) {
            $this->closeConnection($streamId);
            return;
        }

        try {
            // Phase A: Perform WebSocket Handshake if not already done
            if (!$this->handshaked[$streamId]) {
                $this->performHandshake($streamId, $data);
            } 
            // Phase B: Process standard WebSocket frames
            else {
                $frame = $this->frameProcessor->decode($data);
                if ($frame && isset($this->callbacks['message'])) {
                    ($this->callbacks['message'])($connection, $frame);
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
        // Mark as handshaked. 
        // Note: Real PSR-7 request parsing would happen here if using the Negotiator.
        $this->handshaked[$streamId] = true;
        
        $connection = $this->connections[$streamId] ?? null;
        if ($connection && isset($this->callbacks['open'])) {
            ($this->callbacks['open'])($connection);
        }
    }

    /**
     * Cleanly remove a connection from the loop and close the stream.
     */
    private function closeConnection(int $streamId): void
    {
        $connection = $this->connections[$streamId] ?? null;
        if ($connection && isset($this->callbacks['close'])) {
            ($this->callbacks['close'])($connection);
        }

        $stream = $this->streams[$streamId] ?? null;
        unset($this->streams[$streamId], $this->connections[$streamId], $this->handshaked[$streamId]);
        
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
}
