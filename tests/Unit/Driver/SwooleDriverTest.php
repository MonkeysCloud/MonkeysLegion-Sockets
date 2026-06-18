<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\SwooleDriver;
use MonkeysLegion\Sockets\Driver\SwooleConnection;

/**
 * SwooleDriverTest
 * 
 * Verifies that the Swoole driver is correctly initialized and 
 * maps events to our internal contract.
 */
final class SwooleDriverTest extends TestCase
{
    #[Test]
    public function it_can_be_instantiated_without_triggering_errors(): void
    {
        // Simple smoke test: check if the classes work
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension not available');
        }

        $driver = new SwooleDriver();
        $this->assertInstanceOf(SwooleDriver::class, $driver);
    }

    #[Test]
    public function it_can_instantiate_a_connection_wrapper(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension not available');
        }

        // We stub the Server because we only need it as a value object dependency
        $server = $this->createStub(\Swoole\WebSocket\Server::class);
        $connection = new SwooleConnection(1, $server, ['test' => 'meta']);

        $this->assertSame('1', $connection->getId());
        $this->assertSame(['test' => 'meta'], $connection->getMetadata());
    }

    #[Test]
    public function it_resets_signal_handlers_in_ipc_process(): void
    {
        if (!extension_loaded('swoole') || !extension_loaded('pcntl')) {
            $this->markTestSkipped('Swoole and pcntl extensions required');
        }

        // Setup a dummy pcntl signal handler in the parent (simulating SocketServerCommand)
        $parentSignalTriggered = false;
        \pcntl_async_signals(true);
        \pcntl_signal(SIGTERM, function () use (&$parentSignalTriggered) {
            $parentSignalTriggered = true;
            exit(0);
        });

        $driver = new SwooleDriver();
        $driver->registerIpcProcess(function () {
            // Keep the child process alive so we can send a signal to it
            \usleep(500000);
        });

        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('pendingProcesses');
        $prop->setAccessible(true);
        /** @var array<\Swoole\Process> $processes */
        $processes = $prop->getValue($driver);
        $process = $processes[0];

        $pid = $process->start();
        $this->assertGreaterThan(0, $pid);

        \usleep(100000);
        \Swoole\Process::kill($pid, SIGTERM);

        $result = \Swoole\Process::wait(true);

        \pcntl_signal(SIGTERM, SIG_DFL);

        $this->assertFalse($parentSignalTriggered, "Parent signal handler should not be triggered in the child process");
        $this->assertIsArray($result);
        // If the process exited due to SIGTERM, signal key will be 15
        $this->assertSame(SIGTERM, $result['signal'] ?? null);
    }
}
