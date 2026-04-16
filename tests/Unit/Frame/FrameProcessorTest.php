<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Frame;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Frame\Frame;

/**
 * FrameProcessorTest
 * 
 * Unit tests for verifying RFC 6455 frame binary encoding and decoding.
 */
#[CoversClass(FrameProcessor::class)]
#[CoversClass(Frame::class)]
final class FrameProcessorTest extends TestCase
{
    private FrameProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new FrameProcessor();
    }

    #[Test]
    public function it_encodes_and_decodes_unmasked_text_frame(): void
    {
        $payload = 'Hello';
        $encoded = $this->processor->encode($payload);
        
        // 0x81 (Fin + Text) + 0x05 (Length)
        $this->assertSame(0x81, \ord($encoded[0]));
        $this->assertSame(0x05, \ord($encoded[1]));
        $this->assertSame($payload, \substr($encoded, 2));

        $frame = $this->processor->decode($encoded);
        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame($payload, $frame->getPayload());
        $this->assertSame(0x1, $frame->getOpcode());
        $this->assertTrue($frame->isFinal());
        $this->assertFalse($frame->isMasked());
    }

    #[Test]
    public function it_encodes_and_decodes_masked_text_frame(): void
    {
        $payload = 'Hello';
        $encoded = $this->processor->encode($payload, mask: true);
        
        $this->assertSame(0x81, \ord($encoded[0]));
        $this->assertTrue((\ord($encoded[1]) & 0x80) === 0x80); // Mask bit set

        $frame = $this->processor->decode($encoded);
        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame($payload, $frame->getPayload());
        $this->assertTrue($frame->isMasked());
    }
}
