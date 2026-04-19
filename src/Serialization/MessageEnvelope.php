<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Serialization;

/**
 * MessageEnvelope
 * 
 * A DTO representing a serialized WebSocket message structure.
 */
final readonly class MessageEnvelope
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $event,
        public mixed $data,
        public array $metadata = []
    ) {}
}
