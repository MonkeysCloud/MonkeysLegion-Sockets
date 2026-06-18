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
        $driver   = $this->createMock(DriverInterface::class);
        $registry = $this->createStub(ConnectionRegistryInterface::class);

        $registryStub    = $this->createStub(ConnectionRegistryInterface::class);
        $broadcasterStub = $this->createStub(\MonkeysLegion\Sockets\Contracts\BroadcasterInterface::class);
        $formatterStub   = $this->createStub(\MonkeysLegion\Sockets\Contracts\FormatterInterface::class);
        $webSocketServer = new WebSocketServer($registryStub, $broadcasterStub, $formatterStub);

        $config = new Config([
            'sockets' => [
                'driver'    => 'stream',
                'broadcast' => 'redis',
                'host'      => '127.0.0.1',
                'port'      => 8080,
            ],
        ]);

        // Make the driver throw an exception from listen() to return early
        $driver->expects($this->once())
            ->method('listen')
            ->will($this->throwException(new \RuntimeException('Stop server immediately for testing')));

        // Setup the bootstrapper mock — verify it receives the correct driver & server
        $bootstrapper = $this->createMock(SocketServerBootstrapInterface::class);
        $bootstrapper->expects($this->once())
            ->method('boot')
            ->willReturnCallback(function ($d, $s) use ($driver, $webSocketServer): void {
                $this->assertSame($driver, $d);
                $this->assertSame($webSocketServer, $s);
            });

        // Setup container mock
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('has')
            ->with(SocketServerBootstrapInterface::class)
            ->willReturn(true);
        $container->expects($this->once())
            ->method('get')
            ->with(SocketServerBootstrapInterface::class)
            ->willReturn($bootstrapper);

        global $argv;
        $oldArgv = $argv;
        $argv    = ['bin/sockets', 'socket:serve', 'start'];

        try {
            // $redis is the 4th positional arg (null = not injected)
            $command = new SocketServerCommand(
                $driver,
                $config,
                $registry,
                null,            // $redis
                $webSocketServer,
                $container
            );
            $command->__invoke();
        } finally {
            $argv = $oldArgv;
        }
    }
}
