<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Frame;

use MonkeysLegion\Sockets\Contracts\MessageInterface;

/**
 * Frame
 * 
 * Represents a discrete WebSocket frame as defined by RFC 6455.
 * Handles payload, opcodes, and masking metadata.
 */
final class Frame implements MessageInterface
{
    public function __construct(
        private readonly string $payload,
        private readonly int $opcode = 0x1,
        private readonly bool $isFinal = true,
        private readonly bool $isMasked = false,
        private readonly ?string $maskingKey = null,
    ) {}

    /**
     * Get the raw payload data of the frame.
     */
    public function getPayload(): string
    {
        return $this->payload;
    }

    /**
     * Get the opcode for this frame (e.g., 0x1 for text, 0x2 for binary).
     */
    public function getOpcode(): int
    {
        return $this->opcode;
    }

    /**
     * Determine if this is the final fragment of a message.
     */
    public function isFinal(): bool
    {
        return $this->isFinal;
    }

    /**
     * Determine if the payload is binary data.
     */
    public function isBinary(): bool
    {
        return $this->opcode === 0x2;
    }

    /**
     * Check if the frame is masked.
     */
    public function isMasked(): bool
    {
        return $this->isMasked;
    }

    /**
     * Get the 4-byte masking key if available.
     */
    public function getMaskingKey(): ?string
    {
        return $this->maskingKey;
    }
}
