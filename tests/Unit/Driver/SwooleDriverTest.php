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
}
