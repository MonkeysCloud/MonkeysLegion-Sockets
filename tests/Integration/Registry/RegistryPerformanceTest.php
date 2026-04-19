<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Registry;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;

/**
 * RegistryPerformanceTest
 * 
 * Verifies that the ConnectionRegistry handles massive "Tag Storms" 
 * with O(1) efficiency and no memory leaks.
 */
final class RegistryPerformanceTest extends TestCase
{
    #[Test]
    public function it_handles_tag_storm_with_constant_cleanup_time(): void
    {
        $registry = new ConnectionRegistry();
        $targetCount = 5000;
        $tagsPerConn = 50;

        // 1. Setup the Storm
        $connections = [];
        for ($i = 0; $i < $targetCount; $i++) {
            $conn = $this->createStub(ConnectionInterface::class);
            $conn->method('getId')->willReturn("conn-$i");
            $registry->add($conn);
            
            // Each joins 50 rooms
            for ($t = 0; $t < $tagsPerConn; $t++) {
                $registry->tag($conn, "room-$t-$i");
            }
            $connections[] = $conn;
        }

        // 2. Measure removal of the LAST user (worst case in some systems)
        $lastUser = \end($connections);
        
        $start = \hrtime(true);
        $registry->remove($lastUser);
        $end = \hrtime(true);

        $durationNs = $end - $start;
        $durationMs = $durationNs / 1_000_000;

        // O(1) check: Removing one user should be nearly instantaneous
        // regardless of total system size.
        $this->assertLessThan(1.0, $durationMs, "Removal took too long ({$durationMs}ms). O(N) hidden loop detected!");
    }

    #[Test]
    public function it_has_zero_leaks_after_massive_cleanup(): void
    {
        $registry = new ConnectionRegistry();
        $count = 1000;

        // Baseline memory
        \gc_collect_cycles();
        $baseline = \memory_get_usage();

        // Storm
        $conns = [];
        for ($i = 0; $i < $count; $i++) {
            $conn = $this->createStub(ConnectionInterface::class);
            $conn->method('getId')->willReturn("c-$i");
            $registry->add($conn);
            $registry->tag($conn, "room-$i");
            $conns[] = $conn;
        }

        // Cleanup
        foreach ($conns as $c) {
            $registry->remove($c);
        }
        unset($conns);

        \gc_collect_cycles();
        $after = \memory_get_usage();

        // Memory should return roughly to baseline (small overhead is normal but not MBs)
        $diffKb = ($after - $baseline) / 1024;
        $this->assertLessThan(256, $diffKb, "Possible memory leak detected! Registry overhead: {$diffKb}KB");
    }
}
