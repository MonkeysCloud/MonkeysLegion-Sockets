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
    /** @var resource|null */
    private mixed $server = null;

    /** @var array<int, StreamConnection> */
    private array $connections = [];

    /** @var array<int, resource> */
    private array $streams = [];

    /** @var array<int, bool> */
    private array $handshaked = [];

    /** @var array<string, callable(mixed...): void> */
    private array $callbacks = [];

    private bool $running = false;

    public function __construct(
        private readonly FrameProcessor $frameProcessor = new FrameProcessor(),
        private readonly ?HandshakeNegotiator $negotiator = null,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

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

    private function loop(): void
    {
        while ($this->running) {
            $read = $this->streams;
            $write = $except = null;

            if (empty($read)) {
                \usleep(10000);
                continue;
            }

            if (@\stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $stream) {
                if ($stream === $this->server) {
                    $this->acceptConnection();
                } else {
                    $this->handleData($stream);
                }
            }
        }
    }

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

        try {
            if (!$this->handshaked[$streamId]) {
                $this->performHandshake($streamId, $data);
            } else {
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

    private function performHandshake(int $streamId, string $data): void
    {
        $this->handshaked[$streamId] = true;
        
        $connection = $this->connections[$streamId] ?? null;
        if ($connection && isset($this->callbacks['open'])) {
            ($this->callbacks['open'])($connection);
        }
    }

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
