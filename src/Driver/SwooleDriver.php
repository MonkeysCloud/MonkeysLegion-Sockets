<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\DriverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Throwable;

/**
 * SwooleDriver
 * 
 * High-performance WebSocket driver leveraging the Swoole engine.
 * Best suited for extreme concurrency and production environments.
 */
final class SwooleDriver implements DriverInterface
{
    /** @var Server|null Swoole server instance */
    private ?Server $server = null;

    /** @var array<int, SwooleConnection> Connection tracker */
    private array $connections = [];

    /** @var array<string, callable(mixed...): void> Callbacks */
    private array $callbacks = [];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    public function listen(string $address, int $port): void
    {
        $this->server = new Server($address, $port);

        // Configure Swoole for maximum throughput
        $this->server->set([
            'open_websocket_close_frame' => true,
            'websocket_compression' => true,
        ]);

        $this->server->on('open', function (Server $server, $request) {
            $fd = $request->fd;
            $connection = new SwooleConnection($fd, $server, [
                'header' => $request->header ?? [],
                'server' => $request->server ?? [],
                'get' => $request->get ?? [],
            ]);

            $this->connections[$fd] = $connection;

            if (isset($this->callbacks['open'])) {
                ($this->callbacks['open'])($connection);
            }
        });

        $this->server->on('message', function (Server $server, Frame $frame) {
            $fd = $frame->fd;
            $connection = $this->connections[$fd] ?? null;

            if ($connection && isset($this->callbacks['message'])) {
                // Use our internal Frame DTO which implements MessageInterface
                $message = new \MonkeysLegion\Sockets\Frame\Frame($frame->data, $frame->opcode);
                ($this->callbacks['message'])($connection, $message);
            }
        });

        $this->server->on('close', function (Server $server, int $fd) {
            $connection = $this->connections[$fd] ?? null;
            if ($connection) {
                if (isset($this->callbacks['close'])) {
                    ($this->callbacks['close'])($connection);
                }
                unset($this->connections[$fd]);
            }
        });

        $this->logger->info("Swoole WebSocket server starting on {$address}:{$port}");
        $this->server->start();
    }

    public function stop(): void
    {
        $this->server?->shutdown();
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
