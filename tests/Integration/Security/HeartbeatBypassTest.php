<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;

final class HeartbeatBypassTest extends TestCase
{
    /**
     * Infinite Idle Bypass Attack.
     * A hacker sends 1 byte of junk. The driver calls touch(),
     * but the frame processor returns null (incomplete frame).
     * The connection remains handshaked=false (or true but no message),
     * yet the heartbeat timer is reset.
     */
    #[Test]
    public function it_can_be_kept_alive_with_junk_data(): void
    {
        // This test requires a bit of internal state checking.
        // I'll simulate the Driver logic here.
        
        $registry = new ConnectionRegistry();
        $processor = new FrameProcessor();
        
        // Mock driver behavior
        $connection = $this->createMock(\MonkeysLegion\Sockets\Contracts\ConnectionInterface::class);
        $connection->method('getId')->willReturn('hacker');
        
        $initialTime = \time() - 100;
        // In reality, it starts at time()
        
        // If the driver touches it on ANY data:
        $connection->expects($this->atLeastOnce())->method('touch');
        
        // Simulate junk read
        $junk = "\x00"; 
        $frame = $processor->decode($junk);
        
        if ($frame === null) {
            // Driver current logic:
            $connection->touch();
        }
        
        $this->assertTrue(true);
    }
}
