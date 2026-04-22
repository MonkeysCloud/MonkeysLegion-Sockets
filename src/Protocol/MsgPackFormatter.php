<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Protocol;

use MonkeysLegion\Sockets\Contracts\FormatterInterface;
use MessagePack\MessagePack;
use Throwable;
use RuntimeException;

/**
 * MsgPackFormatter
 * 
 * High-performance binary formatter using MessagePack.
 * Reduces payload size and parsing overhead compared to JSON.
 */
class MsgPackFormatter implements FormatterInterface
{
    /**
     * @inheritDoc
     */
    public function format(string $event, mixed $data = [], array $meta = []): string
    {
        try {
            return MessagePack::pack([
                'event' => $event,
                'data' => $data,
                'meta' => \array_merge($meta, [
                    't' => \microtime(true)
                ]),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to format MessagePack payload: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function parse(string $payload): array
    {
        try {
            $decoded = MessagePack::unpack($payload);

            return [
                'event' => (string) ($decoded['event'] ?? 'unknown'),
                'data'  => $decoded['data'] ?? null,
                'meta'  => (array) ($decoded['meta'] ?? []),
            ];
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to parse MessagePack payload: " . $e->getMessage(), 0, $e);
        }
    }
}
