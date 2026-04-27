<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Providers\SocketServiceProvider;
use MonkeysLegion\Sockets\Server\WebSocketServer;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Service\DriverFactory;
use MonkeysLegion\DI\Container;
use MonkeysLegion\Mlc\Config;
use Redis;

final class SocketServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }
        Container::setInstance(new Container());
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    #[Test]
    public function it_provides_all_essential_socket_services(): void
    {
        $provider = new SocketServiceProvider();
        
        $container = Container::instance();

        // Use real Config object since it is final
        $config = new Config([
            'sockets' => [
                'driver' => 'stream',
                'drivers' => [
                    'stream' => ['host' => '127.0.0.1', 'port' => 8080]
                ]
            ]
        ]);
        $container->set(Config::class, $config);
        
        // Mocking the Redis dependency that must be provided by the app
        $redis = $this->createStub(Redis::class);
        $container->set(Redis::class, $redis);

        $provider->register($config);

        $this->assertTrue($container->has(ConnectionRegistryInterface::class));
        $this->assertTrue($container->has(DriverFactory::class));
        $this->assertTrue($container->has(DriverInterface::class));
        $this->assertTrue($container->has(BroadcasterInterface::class));
        $this->assertTrue($container->has(WebSocketServer::class));

        $server = $container->get(WebSocketServer::class);
        $this->assertInstanceOf(WebSocketServer::class, $server);
        
        // Circular check: Verify registry was injected into server
        $this->assertSame($container->get(ConnectionRegistryInterface::class), $server->getRegistry());
    }
}
