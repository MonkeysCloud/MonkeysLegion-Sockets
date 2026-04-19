<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Serialization;

use MonkeysLegion\Sockets\Contracts\MessageSerializerInterface;
use InvalidArgumentException;
use JsonException;
use MonkeysLegion\Sockets\Serialization\MessageEnvelope;

/**
 * JsonMessageSerializer
 * 
 * Implements the Envelope Pattern using JSON.
 * Preserves numeric types and object-like structures via an 'event' key.
 */
final readonly class JsonMessageSerializer implements MessageSerializerInterface
{
    /**
     * Serialize data into a JSON envelope.
     * 
     * @param array<string, mixed> $metadata
     * @throws JsonException
     */
    public function serialize(string $event, mixed $data, array $metadata = []): string
    {
        $envelope = [
            'event' => $event,
            'data' => $data,
            'metadata' => $metadata,
            'timestamp' => \time(),
        ];

        return \json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Unserialize a JSON string into a MessageEnvelope DTO.
     * 
     * @throws InvalidArgumentException|JsonException
     */
    public function unserialize(string $payload): MessageEnvelope
    {
        $decoded = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded) || !isset($decoded['event'], $decoded['data'])) {
            throw new InvalidArgumentException('Invalid message envelope: missing event or data keys.');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = (array) ($decoded['metadata'] ?? []);
        $event = $decoded['event'];

        return new MessageEnvelope(
            \is_string($event) ? $event : (string) $event,
            $decoded['data'],
            $metadata
        );
    }
}
