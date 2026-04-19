<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Frame\MessageAssembler;
use MonkeysLegion\Sockets\Frame\Frame;

/**
 * AdversarialFragmentationTest
 * 
 * Verifies the system handles "Binary Fragment Torture" by correctly reassembling
 * messages split across many tiny frames and enforcing memory limits.
 */
final class AdversarialFragmentationTest extends TestCase
{
    #[Test]
    public function it_reassembles_highly_fragmented_messages(): void
    {
        $assembler = new MessageAssembler();
        $processor = new FrameProcessor();
        
        $message = "This is a very fragmented message that should be reassembled successfully.";
        $chunks = \str_split($message, 1);
        $streamId = 123;
        
        $assembledFrame = null;
        $count = \count($chunks);

        foreach ($chunks as $index => $char) {
            $isFirst = $index === 0;
            $isLast = $index === $count - 1;
            
            // First frame has opcode (e.g., 0x1 Text), others have 0x0
            $opcode = $isFirst ? 0x1 : 0x0;
            
            $raw = $processor->encode($char, $opcode, $isLast);
            $frame = $processor->decode($raw);
            
            $this->assertNotNull($frame);
            $result = $assembler->assemble($streamId, $frame);
            
            if ($isLast) {
                $assembledFrame = $result;
            } else {
                $this->assertNull($result, "Assembler should return null until finishing the sequence.");
            }
        }

        $this->assertNotNull($assembledFrame);
        $this->assertSame($message, $assembledFrame->getPayload());
        $this->assertSame(0x1, $assembledFrame->getOpcode());
    }

    #[Test]
    public function it_prevents_oom_via_fragmentation_bombs(): void
    {
        $limit = 1024 * 64; // 64KB
        $assembler = new MessageAssembler($limit);
        $streamId = 999;

        // Send a frame to start (Text)
        $frame1 = new Frame(\str_repeat('A', 32 * 1024), 0x1, false);
        $assembler->assemble($streamId, $frame1);

        // Send second frame that exceeds the 64KB limit
        $frame2 = new Frame(\str_repeat('B', 40 * 1024), 0x0, false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Message size exceeded limit");

        $assembler->assemble($streamId, $frame2);
    }
}
