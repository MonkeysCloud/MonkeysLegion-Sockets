<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Registry;

use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use Redis;

/**
 * PhpRedisClient
 * 
 * Concrete implementation of RedisClientInterface wrapping the native phpredis extension.
 */
final readonly class PhpRedisClient implements RedisClientInterface
{
    public function __construct(private Redis $redis) {}

    public function sAdd(string $key, string $value): int
    {
        $result = $this->redis->sAdd($key, $value);
        return \is_int($result) ? $result : (int) $result;
    }

    public function sRem(string $key, string $value): int
    {
        $result = $this->redis->sRem($key, $value);
        return \is_int($result) ? $result : (int) $result;
    }

    public function sMembers(string $key): array
    {
        $result = $this->redis->sMembers($key);
        return \is_array($result) ? $result : [];
    }

    public function del(string $key): int
    {
        $result = $this->redis->del($key);
        return \is_int($result) ? $result : (int) $result;
    }
}
