<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Frame;

use RuntimeException;

/**
 * MessageAssembler
 * 
 * Reassembles fragmented WebSocket frames (FIN bit = 0) into complete messages.
 * Prevents memory exhaustion by enforcing payload limits.
 */
final class MessageAssembler
{
    /** @var array<int, string> Buffered payloads indexed by stream ID */
    private array $buffers = [];

    /** @var array<int, int> Initial opcodes preserved for the full message */
    private array $opcodes = [];

    public function __construct(
        private readonly int $maxMessageSize = 10 * 1024 * 1024 // 10MB Default
    ) {}

    /**
     * Add a frame to the assembler.
     * Returns a completed Frame object if the message is fully assembled, null otherwise.
     */
    public function assemble(int $streamId, Frame $frame): ?Frame
    {
        $opcode = $frame->getOpcode();

        // 1. New Message (Text or Binary)
        if ($opcode !== 0x0) {
            if (isset($this->buffers[$streamId])) {
                throw new RuntimeException("Protocol Error: Received new message frame before finishing previous fragments.");
            }

            if ($frame->isFinal()) {
                return $frame;
            }

            $this->buffers[$streamId] = $frame->getPayload();
            $this->opcodes[$streamId] = $opcode;
            return null;
        }

        // 2. Continuation Frame (Opcode 0x0)
        if (!isset($this->buffers[$streamId])) {
            throw new RuntimeException("Protocol Error: Received continuation frame without a starting message frame.");
        }

        $newPayload = $this->buffers[$streamId] . $frame->getPayload();

        // 3. Prevent Memory Exhaustion (Backpressure for reassembly)
        if (\strlen($newPayload) > $this->maxMessageSize) {
            $this->clear($streamId);
            throw new RuntimeException("Message size exceeded limit ({$this->maxMessageSize} bytes).");
        }

        if ($frame->isFinal()) {
            $assembledFrame = new Frame(
                $newPayload,
                $this->opcodes[$streamId],
                true,
                $frame->isMasked(),
                $frame->getMaskingKey()
            );
            $this->clear($streamId);
            return $assembledFrame;
        }

        $this->buffers[$streamId] = $newPayload;
        return null;
    }

    /**
     * Clear the buffer for a specific stream.
     */
    public function clear(int $streamId): void
    {
        unset($this->buffers[$streamId], $this->opcodes[$streamId]);
    }
}
