<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Frame;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Frame\FrameProcessor;

#[CoversClass(FrameProcessor::class)]
final class Utf8SecurityTest extends TestCase
{
    #[Test]
    public function it_rejects_invalid_utf8_in_text_frames(): void
    {
        $processor = new FrameProcessor();
        
        // Invalid UTF-8 sequence (Incomplete multi-byte sequence)
        $invalidPayload = "\xc3\x28"; 
        
        // Encode as Text frame (0x1)
        $raw = $processor->encode($invalidPayload, opcode: 0x1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid UTF-8');
        $this->expectExceptionCode(1007);

        $processor->decode($raw);
    }

    #[Test]
    public function it_allows_invalid_utf8_in_binary_frames(): void
    {
        $processor = new FrameProcessor();
        
        // Binary frames (0x2) are NOT required to be valid UTF-8
        $invalidPayload = "\xc3\x28";
        
        $raw = $processor->encode($invalidPayload, opcode: 0x2);
        $frame = $processor->decode($raw);

        $this->assertNotNull($frame);
        $this->assertSame($invalidPayload, $frame->getPayload());
    }
}
