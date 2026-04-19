<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Service;

use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Driver\SwooleDriver;
use MonkeysLegion\Sockets\Driver\ReactSocketDriver;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Frame\MessageAssembler;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use InvalidArgumentException;

/**
 * DriverFactory
 * 
 * Central factory for instantiating WebSocket transport drivers 
 * based on the project configuration.
 */
class DriverFactory
{
    public function __construct(
        private readonly array $config,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    /**
     * Create the configured driver with all dependencies injected.
     */
    public function make(?string $driverName = null): DriverInterface
    {
        $name = $driverName ?? ($this->config['driver'] ?? 'stream');
        $options = $this->config['options'] ?? [];

        // 1. Shared Infrastructure
        $frameProcessor = new FrameProcessor();
        $assembler = new MessageAssembler($options['max_message_size'] ?? 10 * 1024 * 1024);
        $negotiator = new HandshakeNegotiator(new ResponseFactory());

        // 2. Instantiate Driver
        return match (\strtolower($name)) {
            'stream' => new StreamSocketDriver(
                frameProcessor: $frameProcessor,
                assembler: $assembler,
                negotiator: $negotiator,
                logger: $this->logger
            ),
            'swoole' => new SwooleDriver(
                logger: $this->logger
            ),
            'react' => new ReactSocketDriver(
                frameProcessor: $frameProcessor,
                negotiator: $negotiator,
                messageAssembler: $assembler,
                logger: $this->logger
            ),
            default => throw new InvalidArgumentException("Unsupported WebSocket driver: [$name]")
        };
    }
}
