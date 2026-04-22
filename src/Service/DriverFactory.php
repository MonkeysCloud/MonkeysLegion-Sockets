<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Service;

use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Driver\SwooleDriver;
use MonkeysLegion\Sockets\Driver\ReactSocketDriver;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Frame\MessageAssembler;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use MonkeysLegion\Sockets\Broadcast\RedisBroadcaster;
use MonkeysLegion\Sockets\Registry\PhpRedisClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use InvalidArgumentException;
use Redis;

/**
 * DriverFactory
 * 
 * Central factory for instantiating WebSocket transport drivers 
 * and associated infrastructure based on project configuration.
 */
class DriverFactory
{
    private ?ConnectionRegistryInterface $registry = null;
    private ?Redis $redis = null;
    private ?HandshakeNegotiator $negotiator = null;

    public function __construct(
        private readonly array $config,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    public function setRegistry(ConnectionRegistryInterface $registry): self
    {
        $this->registry = $registry;
        return $this;
    }

    public function setRedis(?Redis $redis): self
    {
        $this->redis = $redis;
        return $this;
    }

    public function setNegotiator(HandshakeNegotiator $negotiator): self
    {
        $this->negotiator = $negotiator;
        return $this;
    }

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
        
        // Use external negotiator if provided, else fallback to default
        $negotiator = $this->negotiator ?? new HandshakeNegotiator(new ResponseFactory());

        // 2. Instantiate Driver
        $driver = match (\strtolower($name)) {
            'stream' => new StreamSocketDriver(
                frameProcessor: $frameProcessor,
                assembler: $assembler,
                negotiator: $negotiator,
                logger: $this->logger,
                writeBufferSize: $options['write_buffer_size'] ?? 5242880,
                heartbeatInterval: $options['heartbeat_interval'] ?? 60
            ),
            'swoole' => new SwooleDriver(
                logger: $this->logger,
                writeBufferSize: $options['write_buffer_size'] ?? 5242880,
                heartbeatInterval: $options['heartbeat_interval'] ?? 60,
                maxMessageSize: $options['max_message_size'] ?? 10485760
            ),
            'react' => new ReactSocketDriver(
                frameProcessor: $frameProcessor,
                negotiator: $negotiator,
                messageAssembler: $assembler,
                logger: $this->logger,
                writeBufferSize: $options['write_buffer_size'] ?? 5242880,
                heartbeatInterval: $options['heartbeat_interval'] ?? 60,
                maxMessageSize: $options['max_message_size'] ?? 10485760
            ),
            default => throw new InvalidArgumentException("Unsupported WebSocket driver: [$name]")
        };

        if ($this->registry) {
            $driver->setRegistry($this->registry);
        }

        return $driver;
    }

    /**
     * Create the configured broadcaster.
     */
    public function createBroadcaster(): BroadcasterInterface
    {
        if (!$this->redis) {
            throw new \RuntimeException("Redis instance is required for broadcasting but was not provided to the DriverFactory.");
        }

        return new RedisBroadcaster(new PhpRedisClient($this->redis));
    }
}
