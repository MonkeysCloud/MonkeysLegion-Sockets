<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Protocol;

use MonkeysLegion\Sockets\Contracts\FormatterInterface;
use JsonException;
use RuntimeException;

/**
 * JsonFormatter
 * 
 * Default formatter using standard JSON. 
 * Provides high-speed serialization with robust error detection.
 */
class JsonFormatter implements FormatterInterface
{
    /**
     * @inheritDoc
     */
    public function format(string $event, mixed $data = [], array $meta = []): string
    {
        try {
            return \json_encode([
                'event' => $event,
                'data' => $data,
                'meta' => \array_merge($meta, [
                    't' => \microtime(true)
                ]),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException("Failed to format JSON payload: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function parse(string $payload): array
    {
        try {
            $decoded = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
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
        } catch (JsonException $e) {
            throw new RuntimeException("Failed to parse JSON payload: " . $e->getMessage(), 0, $e);
        }
    }
}
