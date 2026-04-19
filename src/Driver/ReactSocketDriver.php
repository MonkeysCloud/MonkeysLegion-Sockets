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
use Throwable;

/**
 * ReactSocketDriver
 * 
 * High-performance, non-blocking asynchronous driver powered by ReactPHP.
 * Ideal for high-concurrency environments where C-extensions are not available.
 */
final class ReactSocketDriver implements DriverInterface
{
    private ?SocketServer $server = null;

    /** @var array<string, callable> Callbacks */
    private array $callbacks = [];

    /** @var array<string, string> Input buffers for fragmented frames or multi-frame reads */
    private array $buffers = [];

    public function __construct(
        private readonly FrameProcessor $frameProcessor = new FrameProcessor(),
        private readonly HandshakeNegotiator $negotiator = new HandshakeNegotiator(new ResponseFactory()),
        private readonly MessageAssembler $messageAssembler = new MessageAssembler(),
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    public function listen(string $address, int $port): void
    {
        $uri = "{$address}:{$port}";
        $this->server = new SocketServer($uri);

        $this->server->on('connection', function (ReactRawConnection $connection) {
            $wrapper = new ReactConnection($connection);
            $id = $wrapper->getId();
            $this->buffers[$id] = '';

            $connection->on('data', function ($data) use ($wrapper, $id) {
                $this->buffers[$id] .= $data;

                if (!$wrapper->isUpgraded()) {
                    if (\str_contains($this->buffers[$id], "\r\n\r\n")) {
                        $this->handleHandshake($wrapper);
                    }
                    return;
                }

                $this->handleWebSocketData($wrapper);
            });

            $connection->on('close', function () use ($wrapper, $id) {
                unset($this->buffers[$id]);
                $this->messageAssembler->clear($id);
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

    private function handleHandshake(ReactConnection $connection): void
    {
        $id = $connection->getId();
        $buffer = $this->buffers[$id];

        try {
            // Find the end of the HTTP headers
            $pos = \strpos($buffer, "\r\n\r\n");
            if ($pos === false) return;

            $handshakeData = \substr($buffer, 0, $pos + 4);
            $rest = \substr($buffer, $pos + 4);

            $request = RequestParser::parse($handshakeData);
            $response = $this->negotiator->negotiate($request);
            
            // Send Handshake Response
            $responseStr = "HTTP/1.1 101 Switching Protocols\r\n";
            foreach ($response->getHeaders() as $name => $values) {
                $responseStr .= "$name: " . \implode(', ', $values) . "\r\n";
            }
            $responseStr .= "\r\n";
            
            $connection->send($responseStr);
            $connection->setUpgraded(true);
            
            // Preserve the rest of the buffer for WebSocket frame processing
            $this->buffers[$id] = $rest;

            if (isset($this->callbacks['open'])) {
                ($this->callbacks['open'])($connection);
            }

            // Immediately process the remaining buffer if it contains frames
            if ($rest !== '') {
                $this->handleWebSocketData($connection);
            }

        } catch (Throwable $e) {
            $this->logger->error("Handshake failed: " . $e->getMessage());
            $connection->close();
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

                $message = $this->messageAssembler->assemble($id, $frame);
                if ($message && isset($this->callbacks['message'])) {
                    ($this->callbacks['message'])($connection, $message);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error("WebSocket Protocol Error: " . $e->getMessage());
            $connection->close();
        }
    }

    public function stop(): void
    {
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
