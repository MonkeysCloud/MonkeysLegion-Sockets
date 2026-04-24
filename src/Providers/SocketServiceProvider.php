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
        $container = $this->container();

        // 1. Redis Client (Shared Infrastructure)
        $container->set(RedisClientInterface::class, fn() => new PhpRedisClient(
            $this->has(Redis::class) 
                ? $this->resolve(Redis::class) 
                : throw new RuntimeException("Redis instance (Redis::class) must be registered in the container when using 'redis' strategies.")
        ));

        // 2. Connection Registry
        $container->set(ConnectionRegistryInterface::class, fn() => ($config['registry'] ?? 'local') === 'redis'
            ? new RedisConnectionRegistry(new ConnectionRegistry(), $this->resolve(RedisClientInterface::class))
            : new ConnectionRegistry()
        );

        // 3. Security & Middleware
        $container->set(MiddlewarePipeline::class, function() use ($config) {
            $pipeline = new MiddlewarePipeline();
            
            if (isset($config['security']['allowed_origins'])) {
                $pipeline->add(new AllowedOriginsMiddleware(
                    (array) $config['security']['allowed_origins'],
                    new ResponseFactory()
                ));
            }
            
            return $pipeline;
        });

        $container->set(HandshakeNegotiator::class, fn() => new HandshakeNegotiator(
            new ResponseFactory(),
            pipeline: $this->resolve(MiddlewarePipeline::class)
        ));

        // 4. Driver Factory
        $container->set(DriverFactory::class, fn() => (new DriverFactory($config))
            ->setRegistry($this->resolve(ConnectionRegistryInterface::class))
            ->setNegotiator($this->resolve(HandshakeNegotiator::class))
            ->setRedis($this->has(Redis::class) ? $this->resolve(Redis::class) : null)
        );

        // 5. Concrete Driver
        $container->set(DriverInterface::class, fn() => $this->resolve(DriverFactory::class)->make());

        // 6. Broadcaster
        $container->set(BroadcasterInterface::class, fn() => ($config['broadcast'] ?? 'redis') === 'unix'
            ? new UnixBroadcaster($config['unix']['path'] ?? '/tmp/ml_sockets.sock')
            : new RedisBroadcaster($this->resolve(RedisClientInterface::class), $config['redis']['channel'] ?? 'ml_sockets:broadcast')
        );

        // 7. Formatter
        $container->set(FormatterInterface::class, fn() => ($config['formatter'] ?? 'json') === 'msgpack'
            ? new MsgPackFormatter()
            : new JsonFormatter()
        );

        // 8. The Master Orchestrator (WebSocketServer)
        $container->set(WebSocketServer::class, fn() => (new WebSocketServer(
            $this->resolve(ConnectionRegistryInterface::class),
            $this->resolve(BroadcasterInterface::class),
            $this->resolve(FormatterInterface::class)
        ))->setDriver($this->resolve(DriverInterface::class)));
    }
}
