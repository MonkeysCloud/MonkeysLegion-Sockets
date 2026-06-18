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
use MonkeysLegion\Sockets\Broadcast\UnixBroadcaster;

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

    /**
     * @param array<string, mixed> $config
     */
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
        $driverConfig = $this->config['driver'] ?? 'stream';
        $name = $driverName ?? (\is_string($driverConfig) ? $driverConfig : 'stream');
        
        $options = \is_array($this->config['options'] ?? null) ? $this->config['options'] : [];

        $maxMessageSize = isset($options['max_message_size']) && \is_scalar($options['max_message_size']) ? (int) $options['max_message_size'] : 10 * 1024 * 1024;
        $writeBufferSize = isset($options['write_buffer_size']) && \is_scalar($options['write_buffer_size']) ? (int) $options['write_buffer_size'] : 5242880;
        $heartbeatInterval = isset($options['heartbeat_interval']) && \is_scalar($options['heartbeat_interval']) ? (int) $options['heartbeat_interval'] : 60;

        // 1. Shared Infrastructure
        $frameProcessor = new FrameProcessor();
        $assembler = new MessageAssembler($maxMessageSize);
        
        // Use external negotiator if provided, else fallback to default
        $negotiator = $this->negotiator ?? new HandshakeNegotiator(new ResponseFactory());

        // 2. Instantiate Driver
        $driver = match (\strtolower($name)) {
            'stream' => new StreamSocketDriver(
                frameProcessor: $frameProcessor,
                assembler: $assembler,
                negotiator: $negotiator,
                logger: $this->logger,
                writeBufferSize: $writeBufferSize,
                heartbeatInterval: $heartbeatInterval
            ),
            'swoole' => new SwooleDriver(
                negotiator: $negotiator,
                logger: $this->logger,
                writeBufferSize: $writeBufferSize,
                heartbeatInterval: $heartbeatInterval,
                maxMessageSize: $maxMessageSize
            ),
            'react' => new ReactSocketDriver(
                frameProcessor: $frameProcessor,
                negotiator: $negotiator,
                messageAssembler: $assembler,
                logger: $this->logger,
                writeBufferSize: $writeBufferSize,
                heartbeatInterval: $heartbeatInterval,
                maxMessageSize: $maxMessageSize
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
        $broadcastConfig = $this->config['broadcast'] ?? 'redis';
        $broadcast = \is_string($broadcastConfig) ? $broadcastConfig : 'redis';
        
        if ($broadcast === 'unix') {
            $unixConfig = $this->config['unix'] ?? [];
            $unixPath = \is_array($unixConfig) && isset($unixConfig['path']) && \is_string($unixConfig['path'])
                ? $unixConfig['path'] 
                : '/tmp/ml_sockets.sock';
            return new UnixBroadcaster($unixPath);
        }

        if (!$this->redis) {
            throw new \RuntimeException("Redis instance is required for RedisBroadcaster but was not provided to the DriverFactory.");
        }

        $redisConfig = $this->config['redis'] ?? [];
        $redisChannel = \is_array($redisConfig) && isset($redisConfig['channel']) && \is_string($redisConfig['channel'])
            ? $redisConfig['channel']
            : 'ml_sockets:broadcast';

        return new RedisBroadcaster(new PhpRedisClient($this->redis), $redisChannel);
    }
}
