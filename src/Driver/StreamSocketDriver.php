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

    public function __construct(
        private readonly FrameProcessor $frameProcessor = new FrameProcessor(),
        private readonly MessageAssembler $assembler = new MessageAssembler(),
        private readonly HandshakeNegotiator $negotiator = new HandshakeNegotiator(new ResponseFactory()),
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

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

            // 3. Handle writable sockets (Clear buffers)
            if ($write) {
                foreach ($write as $stream) {
                    $id = (int) $stream;
                    $this->connections[$id]?->flush();
                }
            }

            // 4. Handle readable sockets (Accept or Read data)
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

        $data = @\fread($stream, 8192);

        if ($data === '' || $data === false) {
            $this->closeConnection($streamId);
            return;
        }

        $this->buffers[$streamId] ??= '';
        $this->buffers[$streamId] .= $data;

        $connection->touch();

        try {
            if (!$this->handshaked[$streamId]) {
                $this->performHandshake($streamId, $data);
            } else {
                while (\strlen($this->buffers[$streamId]) >= 2) {
                    $frame = $this->frameProcessor->decode($this->buffers[$streamId]);
                    if (!$frame) {
                        break;
                    }

                    // Slice exactly what was consumed
                    $this->buffers[$streamId] = \substr($this->buffers[$streamId], $frame->getConsumedLength());

                    $assembled = $this->assembler->assemble($streamId, $frame);
                    if ($assembled && isset($this->callbacks['message'])) {
                        ($this->callbacks['message'])($connection, $assembled);
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
            if ($connection && isset($this->callbacks['open'])) {
                ($this->callbacks['open'])($connection);
            }
        } catch (Throwable $e) {
            $this->logger->error("Handshake failed: " . $e->getMessage());
            $errorResponse = "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n";
            @\fwrite($this->streams[$streamId], $errorResponse);
            $this->closeConnection($streamId);
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
