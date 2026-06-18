<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
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

    /** @var array<string, callable> Callbacks */
    private array $callbacks = [];

    /** @var \MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface|null */
    private ?\MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface $registry = null;

    /** @var array<\Swoole\Process> */
    private array $pendingProcesses = [];

    public function __construct(
        private readonly HandshakeNegotiator $negotiator = new HandshakeNegotiator(new ResponseFactory()),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $writeBufferSize = 5242880,
        private readonly int $heartbeatInterval = 60,
        private readonly int $maxMessageSize = 10485760
    ) {}

    public function setRegistry(\MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface $registry): void
    {
        $this->registry = $registry;
    }

    public function listen(string $address, int $port): void
    {
        $server = new Server($address, $port);
        $this->server = $server;

        // Configure Swoole using library-standardized options
        $server->set([
            'open_websocket_close_frame' => true,
            'websocket_compression'      => true,
            'package_max_length'         => $this->maxMessageSize,
            'socket_buffer_size'         => $this->writeBufferSize,
            'heartbeat_check_interval'   => (int) \ceil($this->heartbeatInterval / 2),
            'heartbeat_idle_time'        => $this->heartbeatInterval,
        ]);

        $server->on('handshake', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) use ($server) {
            $method = $request->server['request_method'] ?? 'GET';
            $uri = $request->server['request_uri'] ?? '/';
            if (!empty($request->server['query_string'])) {
                $uri .= '?' . $request->server['query_string'];
            }

            $headers = [];
            foreach ($request->header ?? [] as $name => $value) {
                $headers[$name] = $value;
            }

            $protocol = $request->server['server_protocol'] ?? 'HTTP/1.1';
            $version = \str_starts_with($protocol, 'HTTP/') ? \substr($protocol, 5) : '1.1';

            $psrRequest = new \MonkeysLegion\Sockets\Handshake\MinimalServerRequest(
                $method,
                $uri,
                $headers,
                $version
            );

            try {
                $psrResponse = $this->negotiator->negotiate($psrRequest);
                $status = $psrResponse->getStatusCode();

                $response->status($status);
                foreach ($psrResponse->getHeaders() as $name => $values) {
                    $response->header($name, \implode(', ', $values));
                }

                $response->end();

                if ($status !== 101) {
                    return false;
                }
            } catch (Throwable $e) {
                $this->logger->error("Handshake failed: " . $e->getMessage());
                $response->status(400);
                $response->end();
                return false;
            }

            $fd = $request->fd;
            $connection = new SwooleConnection($fd, $server, [
                'header' => $request->header ?? [],
                'server' => $request->server ?? [],
                'get' => $request->get ?? [],
            ]);

            $this->connections[$fd] = $connection;

            if ($this->registry) {
                $this->registry->add($connection);
            }

            if (isset($this->callbacks['open'])) {
                ($this->callbacks['open'])($connection);
            }

            return true;
        });

        $server->on('message', function (Server $server, Frame $frame) {
            $fd = $frame->fd;
            $connection = $this->connections[$fd] ?? null;

            if ($connection && isset($this->callbacks['message'])) {
                // Use our internal Frame DTO which implements MessageInterface
                $message = new \MonkeysLegion\Sockets\Frame\Frame($frame->data, $frame->opcode);
                ($this->callbacks['message'])($connection, $message);
            }
        });

        $server->on('close', function (Server $server, int $fd) {
            $connection = $this->connections[$fd] ?? null;
            if ($connection) {
                if ($this->registry) {
                    $this->registry->remove($connection);
                }
                if (isset($this->callbacks['close'])) {
                    ($this->callbacks['close'])($connection);
                }
                unset($this->connections[$fd]);
            }
        });

        $server->on('pipeMessage', function (Server $server, int $srcWorkerId, $message) {
            if (isset($this->callbacks['ipc_message'])) {
                ($this->callbacks['ipc_message'])((string) $message);
            }
        });

        foreach ($this->pendingProcesses as $process) {
            $server->addProcess($process);
        }

        $this->logger->info("Swoole WebSocket server starting on {$address}:{$port}");
        $server->start();
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

    public function onIpcMessage(callable $callback): void
    {
        $this->callbacks['ipc_message'] = $callback;
    }

    public function registerIpcProcess(callable $callback): void
    {
        $this->pendingProcesses[] = new \Swoole\Process(function () use ($callback) {
            if (\extension_loaded('pcntl')) {
                \pcntl_signal(SIGTERM, SIG_DFL);
                \pcntl_signal(SIGINT, SIG_DFL);
            }
            try {
                $callback($this->server);
            } catch (Throwable $e) {
                if (\class_exists(\Swoole\ExitException::class) && $e instanceof \Swoole\ExitException) {
                    return;
                }
                throw $e;
            }
        });
    }
}
