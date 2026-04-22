<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

/**
 * FormatterInterface
 * 
 * Responsible for translating between raw wire data (binary/string) 
 * and structured application messages.
 */
interface FormatterInterface
{
    /**
     * Format a message for sending over the wire.
     */
    public function format(string $event, mixed $data = [], array $meta = []): string;

    /**
     * Parse a raw payload from the wire into structured data.
     * 
     * @return array{event: string, data: mixed, meta: array}
     */
    public function parse(string $payload): array;
}
