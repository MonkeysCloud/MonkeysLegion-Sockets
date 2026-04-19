<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

use MonkeysLegion\Sockets\Serialization\MessageEnvelope;

/**
 * MessageSerializerInterface
 * 
 * Defines how structured data is transformed into a format
 * suitable for WebSocket transmission (usually strings).
 */
interface MessageSerializerInterface
{
    /**
     * Serialize data into a string (e.g., JSON envelope).
     */
    public function serialize(string $event, mixed $data, array $metadata = []): string;

    /**
     * Unserialize a string into a structured envelope.
     */
    public function unserialize(string $payload): MessageEnvelope;
}
