<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

/**
 * Interface for a simplified Redis client to avoid hard dependency on specific libraries.
 */
interface RedisClientInterface
{
    public function sAdd(string $key, string $value): int;
    public function sRem(string $key, string $value): int;
    /**
     * @return array<int, string>
     */
    public function sMembers(string $key): array;
    public function del(string $key): int;
    public function publish(string $channel, string $message): int;

    /**
     * @param array<int, string> $channels
     * @param callable(mixed, mixed, mixed): mixed $callback
     */
    public function subscribe(array $channels, callable $callback): void;
}
