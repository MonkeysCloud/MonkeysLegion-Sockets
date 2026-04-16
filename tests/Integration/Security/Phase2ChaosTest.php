<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;

/**
 * Phase2ChaosTest
 * 
 * Breaking Phase 2 components through complexity and resource exhaustion.
 */
final class Phase2ChaosTest extends TestCase
{
    /**
     * Complexity Attack: O(Tags) cleanup in ConnectionRegistry.
     * If a hacker can trigger many tag creations (rooms), removing a connection
     * becomes an O(N) operation where N is the total number of tags in the system.
     */
    #[Test]
    public function it_suffers_from_cross_connection_complexity_dos(): void
    {
        $registry = new ConnectionRegistry();
        
        // 1. Target user in ONE room
        $target = $this->createStub(ConnectionInterface::class);
        $target->method('getId')->willReturn('target-user');
        $registry->add($target);
        $registry->tag($target, "room-target");

        // 2. Build 50,000 rooms with OTHER users
        // In the OLD logic, removing 'target' would iterate ALL these rooms.
        for ($i = 0; $i < 50000; $i++) {
            $other = $this->createStub(ConnectionInterface::class);
            $other->method('getId')->willReturn("other-$i");
            $registry->add($other);
            $registry->tag($other, "room-$i");
        }

        $start = \microtime(true);
        $registry->remove($target);
        $end = \microtime(true);

        $duration = $end - $start;
        
        $this->assertLessThan(0.001, $duration, "Target removal took too long ({$duration}s). Cross-connection DoS risk!");
    }
}
