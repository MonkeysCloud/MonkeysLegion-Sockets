<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Cli\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Cli\Command\SocketServerCommand;
use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\SocketServerBootstrapInterface;
use MonkeysLegion\Sockets\Server\WebSocketServer;
use MonkeysLegion\Mlc\Config;
use Psr\Container\ContainerInterface;

final class SocketServerCommandTest extends TestCase
{
    #[Test]
    public function it_calls_the_bootstrap_hook_if_registered_in_the_container(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $webSocketServer = $this->createMock(WebSocketServer::class);
        
        $config = new Config([
            'sockets' => [
                'driver' => 'stream',
                'broadcast' => 'redis',
                'host' => '127.0.0.1',
                'port' => 8080,
            ],
        ]);

        // Make the driver throw an exception from listen() to return early
        $driver->expects($this->once())
            ->method('listen')
            ->willThrowException(new \RuntimeException('Stop server immediately for testing'));

        // Setup the bootstrapper mock
        $bootstrapper = $this->createMock(SocketServerBootstrapInterface::class);
        $bootstrapper->expects($this->once())
            ->method('boot')
            ->with($driver, $webSocketServer);

        // Setup container mock
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->any())
            ->method('has')
            ->with(SocketServerBootstrapInterface::class)
            ->willReturn(true);
        $container->expects($this->any())
            ->method('get')
            ->with(SocketServerBootstrapInterface::class)
            ->willReturn($bootstrapper);

        $command = new SocketServerCommand(
            $driver,
            $config,
            $registry,
            $webSocketServer,
            $container
        );

        global $argv;
        $oldArgv = $argv;
        $argv = ['bin/sockets', 'socket:serve', 'start'];

        try {
            $command->__invoke();
        } finally {
            $argv = $oldArgv;
        }
    }
}
