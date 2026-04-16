<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

/**
 * Represents a WebSocket message/frame.
 */
interface MessageInterface
{
    /**
     * Get the payload data of the message.
     */
    public function getPayload(): string;

    /**
     * Get the opcode for this frame (e.g., 0x1 for text, 0x2 for binary).
     */
    public function getOpcode(): int;

    /**
     * Determine if this is a final fragment in a sequence of messages.
     */
    public function isFinal(): bool;

    /**
     * Determine if the payload is binary data.
     */
    public function isBinary(): bool;
}
