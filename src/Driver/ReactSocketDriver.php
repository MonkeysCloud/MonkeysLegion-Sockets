<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Driver;

use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use MonkeysLegion\Sockets\Handshake\RequestParser;
use MonkeysLegion\Sockets\Frame\MessageAssembler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Socket\SocketServer;
use React\Socket\ConnectionInterface as ReactRawConnection;
use React\EventLoop\Loop;
use Throwable;

/**
 * ReactSocketDriver
 * 
 * High-performance, non-blocking asynchronous driver powered by ReactPHP.
 */
final class ReactSocketDriver implements DriverInterface
{
    private ?SocketServer $server = null;
    private ?\MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface $registry = null;

    /** @var array<string, callable> Callbacks */
    private array $callbacks = [];

    /** @var array<string, string> Input buffers */
    private array $buffers = [];

    /** @var array<string, ReactConnection> connection tracking */
    private array $activeConnections = [];

    /** @var \React\EventLoop\TimerInterface|null Reaper timer */
    private ?\React\EventLoop\TimerInterface $reaperTimer = null;

    public function __construct(
        private readonly FrameProcessor $frameProcessor = new FrameProcessor(),
        private readonly HandshakeNegotiator $negotiator = new HandshakeNegotiator(new ResponseFactory()),
        private readonly MessageAssembler $messageAssembler = new MessageAssembler(),
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
        $uri = "{$address}:{$port}";
        $this->server = new SocketServer($uri);

        // Start the heartbeat reaper
        $this->startHeartbeatReaper();

        $this->server->on('connection', function (ReactRawConnection $connection) {
            $wrapper = new ReactConnection(
                connection: $connection, 
                frameProcessor: $this->frameProcessor,
                maxWriteBuffer: $this->writeBufferSize
            );
            $id = $wrapper->getId();
            $this->buffers[$id] = '';
            $this->activeConnections[$id] = $wrapper;

            $connection->on('data', function ($data) use ($wrapper, $id) {
                $this->buffers[$id] .= $data;
                $wrapper->touch();

                if (!$wrapper->isUpgraded()) {
                    if (\str_contains($this->buffers[$id], "\r\n\r\n")) {
                        $this->handleHandshake($wrapper);
                    }
                    return;
                }

                $this->handleWebSocketData($wrapper);
            });

            $connection->on('close', function () use ($wrapper, $id) {
                unset($this->buffers[$id], $this->activeConnections[$id]);
                $this->messageAssembler->clear($id);
                if ($this->registry) {
                    $this->registry->remove($wrapper);
                }
                if (isset($this->callbacks['close'])) {
                    ($this->callbacks['close'])($wrapper);
                }
            });

            $connection->on('error', function (Throwable $e) use ($wrapper) {
                if (isset($this->callbacks['error'])) {
                    ($this->callbacks['error'])($wrapper, $e);
                }
            });
        });

        $this->logger->info("ReactPHP WebSocket Server listening on $uri");
    }

    private function startHeartbeatReaper(): void
    {
        $this->reaperTimer = Loop::addPeriodicTimer(
            (float) $this->heartbeatInterval, 
            function () {
                $now = \time();
                foreach ($this->activeConnections as $connection) {
                    $idleTime = $now - $connection->lastActivity();

                    if ($idleTime > ($this->heartbeatInterval * 2)) {
                        $this->logger->info("Closing zombie React connection {$connection->getId()}");
                        $connection->close(1002, 'Heartbeat timeout');
                        continue;
                    }

                    if ($connection->isUpgraded()) {
                        $connection->ping();
                    }
                }
            }
        );
    }

    private function handleHandshake(ReactConnection $connection): void
    {
        $id = $connection->getId();
        $buffer = $this->buffers[$id];

        try {
            $pos = \strpos($buffer, "\r\n\r\n");
            if ($pos === false) return;

            $handshakeData = \substr($buffer, 0, $pos + 4);
            $rest = \substr($buffer, $pos + 4);

            $request = RequestParser::parse($handshakeData);
            $response = $this->negotiator->negotiate($request);
            
            $rawResponse = "HTTP/1.1 101 Switching Protocols\r\n";
            foreach ($response->getHeaders() as $name => $values) {
                $rawResponse .= "$name: " . \implode(', ', $values) . "\r\n";
            }
            $rawResponse .= "\r\n";
            
            $connection->send($rawResponse);
            $connection->setUpgraded(true);
            
            if ($this->registry) {
                $this->registry->add($connection);
            }

            $this->buffers[$id] = $rest;

            if (isset($this->callbacks['open'])) {
                ($this->callbacks['open'])($connection);
            }

            if ($rest !== '') {
                $this->handleWebSocketData($connection);
            }

        } catch (Throwable $e) {
            $this->logger->error("Handshake failed: " . $e->getMessage());
            $connection->close(400, 'Handshake failure');
        }
    }

    private function handleWebSocketData(ReactConnection $connection): void
    {
        $id = $connection->getId();
        
        try {
            while (\strlen($this->buffers[$id] ?? '') >= 2) {
                $frame = $this->frameProcessor->decode($this->buffers[$id]);
                
                if (!$frame) {
                    break;
                }

                $this->buffers[$id] = \substr($this->buffers[$id], $frame->getConsumedLength());

                switch ($frame->getOpcode()) {
                    case 0x8: // Close
                        $connection->close();
                        return;
                    
                    case 0x9: // Ping
                        $pong = $this->frameProcessor->encode($frame->getPayload(), 0xA);
                        $connection->send($pong);
                        break;
                    
                    case 0xA: // Pong
                        // Activity already touched in on('data')
                        break;
                    
                    default:
                        $message = $this->messageAssembler->assemble($id, $frame);
                        if ($message && isset($this->callbacks['message'])) {
                            ($this->callbacks['message'])($connection, $message);
                        }
                        break;
                }
            }
        } catch (Throwable $e) {
            $this->logger->error("WebSocket Protocol Error: " . $e->getMessage());
            $connection->close(1002, $e->getMessage());
        }
    }

    public function stop(): void
    {
        if ($this->reaperTimer) {
            Loop::cancelTimer($this->reaperTimer);
        }

        foreach ($this->activeConnections as $connection) {
            $connection->close(1001, 'Server shutting down');
        }

        $this->server?->close();
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
