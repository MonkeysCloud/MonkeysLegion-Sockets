<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Providers;

use MonkeysLegion\Core\Attribute\Provider;
use MonkeysLegion\DI\Traits\ContainerAware;
use MonkeysLegion\Mlc\Config;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Sockets\Contracts\FormatterInterface;
use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;
use MonkeysLegion\Sockets\Registry\RedisConnectionRegistry;
use MonkeysLegion\Sockets\Registry\PhpRedisClient;
use MonkeysLegion\Sockets\Broadcast\RedisBroadcaster;
use MonkeysLegion\Sockets\Broadcast\UnixBroadcaster;
use MonkeysLegion\Sockets\Service\DriverFactory;
use MonkeysLegion\Sockets\Protocol\JsonFormatter;
use MonkeysLegion\Sockets\Protocol\MsgPackFormatter;
use MonkeysLegion\Sockets\Server\WebSocketServer;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use MonkeysLegion\Sockets\Handshake\MiddlewarePipeline;
use MonkeysLegion\Sockets\Handshake\AllowedOriginsMiddleware;
use Redis;
use RuntimeException;

/**
 * SocketServiceProvider
 * 
 * Integrates the WebSocket stack into the MonkeysLegion DI container.
 */
#[Provider]
class SocketServiceProvider
{
    use ContainerAware;

    /**
     * Provide essential socket services to the application.
     */
    public function register(Config $mlcConfig): void
    {
        $config = $mlcConfig->get('sockets', []);
        if (!\is_array($config)) {
            $config = [];
        }
        $container = $this->container();

        // 1. Redis Client (Shared Infrastructure)
        $container->set(RedisClientInterface::class, function() {
            if (!$this->has(Redis::class)) {
                throw new RuntimeException("Redis instance (Redis::class) must be registered in the container when using 'redis' strategies.");
            }
            $redis = $this->resolve(Redis::class);
            if (!$redis instanceof Redis) {
                throw new RuntimeException("Resolved Redis instance is not an instance of Redis.");
            }
            return new PhpRedisClient($redis);
        });

        // 2. Connection Registry
        $container->set(ConnectionRegistryInterface::class, function() use ($config) {
            $registryType = $config['registry'] ?? 'local';
            if ($registryType === 'redis') {
                /** @var RedisClientInterface $redisClient */
                $redisClient = $this->resolve(RedisClientInterface::class);
                return new RedisConnectionRegistry(new ConnectionRegistry(), $redisClient);
            }
            return new ConnectionRegistry();
        });

        // 3. Security & Middleware
        $container->set(MiddlewarePipeline::class, function() use ($config) {
            $pipeline = new MiddlewarePipeline();
            
            $security = $config['security'] ?? [];
            if (\is_array($security) && isset($security['allowed_origins']) && \is_array($security['allowed_origins'])) {
                /** @var array<string> $allowedOrigins */
                $allowedOrigins = $security['allowed_origins'];
                $pipeline->add(new AllowedOriginsMiddleware(
                    $allowedOrigins,
                    new ResponseFactory()
                ));
            }
            
            return $pipeline;
        });

        $container->set(HandshakeNegotiator::class, function() {
            /** @var MiddlewarePipeline $pipeline */
            $pipeline = $this->resolve(MiddlewarePipeline::class);
            return new HandshakeNegotiator(
                new ResponseFactory(),
                pipeline: $pipeline
            );
        });

        // 4. Driver Factory
        $container->set(DriverFactory::class, function() use ($config) {
            /** @var ConnectionRegistryInterface $registry */
            $registry = $this->resolve(ConnectionRegistryInterface::class);
            /** @var HandshakeNegotiator $negotiator */
            $negotiator = $this->resolve(HandshakeNegotiator::class);
            
            $redisRaw = $this->has(Redis::class) ? $this->resolve(Redis::class) : null;
            $redis = $redisRaw instanceof Redis ? $redisRaw : null;

            return (new DriverFactory($config))
                ->setRegistry($registry)
                ->setNegotiator($negotiator)
                ->setRedis($redis);
        });

        // 5. Concrete Driver
        $container->set(DriverInterface::class, function() {
            /** @var DriverFactory $factory */
            $factory = $this->resolve(DriverFactory::class);
            return $factory->make();
        });

        // 6. Broadcaster
        $container->set(BroadcasterInterface::class, function() use ($config) {
            $broadcast = $config['broadcast'] ?? 'redis';
            if ($broadcast === 'unix') {
                $unix = $config['unix'] ?? [];
                $path = \is_array($unix) && isset($unix['path']) && \is_string($unix['path'])
                    ? $unix['path']
                    : '/tmp/ml_sockets.sock';
                return new UnixBroadcaster($path);
            }

            /** @var RedisClientInterface $redisClient */
            $redisClient = $this->resolve(RedisClientInterface::class);
            $redis = $config['redis'] ?? [];
            $channel = \is_array($redis) && isset($redis['channel']) && \is_string($redis['channel'])
                ? $redis['channel']
                : 'ml_sockets:broadcast';

            return new RedisBroadcaster($redisClient, $channel);
        });

        // 7. Formatter
        $container->set(FormatterInterface::class, function() use ($config) {
            $formatter = $config['formatter'] ?? 'json';
            return $formatter === 'msgpack'
                ? new MsgPackFormatter()
                : new JsonFormatter();
        });

        // 8. The Master Orchestrator (WebSocketServer)
        $container->set(WebSocketServer::class, function() {
            /** @var ConnectionRegistryInterface $registry */
            $registry = $this->resolve(ConnectionRegistryInterface::class);
            /** @var BroadcasterInterface $broadcaster */
            $broadcaster = $this->resolve(BroadcasterInterface::class);
            /** @var FormatterInterface $formatter */
            $formatter = $this->resolve(FormatterInterface::class);
            /** @var DriverInterface $driver */
            $driver = $this->resolve(DriverInterface::class);

            return (new WebSocketServer(
                $registry,
                $broadcaster,
                $formatter
            ))->setDriver($driver);
        });
    }
}
