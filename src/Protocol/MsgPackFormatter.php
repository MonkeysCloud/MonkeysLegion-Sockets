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
            if (!\is_array($decoded)) {
                $decoded = [];
            }

            $event = $decoded['event'] ?? 'unknown';
            $eventStr = \is_string($event) ? $event : (\is_scalar($event) ? (string) $event : 'unknown');

            $meta = $decoded['meta'] ?? [];
            $metaArr = \is_array($meta) ? $meta : [];

            return [
                'event' => $eventStr,
                'data'  => $decoded['data'] ?? null,
                'meta'  => $metaArr,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to parse MessagePack payload: " . $e->getMessage(), 0, $e);
        }
    }
}
