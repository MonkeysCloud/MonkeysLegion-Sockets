<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Frame;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Frame\FrameProcessor;

/**
 * MaskingEntropyTest
 * 
 * Verifies that the CSPRNG-based masking key generation provides 
 * high entropy to prevent "Byte-masking" side-channel attacks.
 */
final class MaskingEntropyTest extends TestCase
{
    #[Test]
    public function it_generates_unique_and_unpredictable_masking_keys(): void
    {
        $processor = new FrameProcessor();
        $payload = 'entropy-test';
        $keys = [];
        $sampleSize = 1000;

        for ($i = 0; $i < $sampleSize; $i++) {
            // Encode with masking enabled
            $raw = $processor->encode($payload, mask: true);
            
            // Extract the 4-byte masking key (offset 2 for small payload)
            $maskingKey = \substr($raw, 2, 4);
            $keys[] = \bin2hex($maskingKey);
        }

        // 1. Uniqueness check: All 1,000 keys must be unique.
        // For a 32-bit (4-byte) key, the collision probability in 1,000 samples 
        // is practically zero with CSPRNG.
        $uniqueKeys = \array_unique($keys);
        
        $this->assertCount(
            $sampleSize, 
            $uniqueKeys, 
            'Detected duplicate masking keys! Potential lack of entropy in random_bytes().'
        );

        // 2. Statistical check: The keys should not follow a simple pattern.
        // We check the distribution of the first byte across the sample.
        $firstBytes = \array_map(fn($k) => \hexdec(\substr($k, 0, 2)), $keys);
        $average = \array_sum($firstBytes) / $sampleSize;

        // The average of random bytes (0-255) should be roughly 127.5.
        // We allow a reasonable margin for 1,000 samples.
        $this->assertEqualsWithDelta(
            127.5, 
            $average, 
            25.0, 
            'The distribution of random bytes is biased. Entropy might be compromised!'
        );
    }
}
