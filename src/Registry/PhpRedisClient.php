<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Registry;

use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use Redis;

/**
 * PhpRedisClient
 * 
 * Concrete implementation of RedisClientInterface wrapping the native phpredis extension.
 * Automatically handles process forking (e.g. under Swoole or pcntl_fork) by reconnecting
 * if the PID changes.
 */
class PhpRedisClient implements RedisClientInterface
{
    private int $connPid;
    private Redis $redis;
    /** @var (callable(): Redis)|null */
    private mixed $redisFactory;

    /**
     * @param Redis $redis
     * @param (callable(): Redis)|null $redisFactory
     */
    public function __construct(Redis $redis, ?callable $redisFactory = null)
    {
        $this->redis = $redis;
        $this->redisFactory = $redisFactory;
        $this->connPid = \getmypid() ?: 0;
    }

    public function sAdd(string $key, string $value): int
    {
        $this->ensureConnection();
        $result = $this->redis->sAdd($key, $value);
        return \is_int($result) ? $result : (int) $result;
    }

    public function sRem(string $key, string $value): int
    {
        $this->ensureConnection();
        $result = $this->redis->sRem($key, $value);
        return \is_int($result) ? $result : (int) $result;
    }

    /**
     * @return array<int, string>
     */
    public function sMembers(string $key): array
    {
        $this->ensureConnection();
        $result = $this->redis->sMembers($key);
        return \is_array($result) ? $result : [];
    }

    public function del(string $key): int
    {
        $this->ensureConnection();
        $result = $this->redis->del($key);
        return \is_int($result) ? $result : (int) $result;
    }

    public function publish(string $channel, string $message): int
    {
        $this->ensureConnection();
        $result = $this->redis->publish($channel, $message);
        return \is_int($result) ? $result : (int) $result;
    }

    /**
     * @param array<int, string> $channels
     */
    public function subscribe(array $channels, callable $callback): void
    {
        $this->ensureConnection();
        $this->redis->subscribe($channels, $callback);
    }

    /**
     * Automatically detect if the process has been forked, and re-establish the connection.
     */
    private function ensureConnection(): void
    {
        $currentPid = \getmypid() ?: 0;
        if ($this->connPid !== $currentPid) {
            try {
                $host = $this->redis->getHost();
                if ($host) {
                    $port = $this->redis->getPort() ?: 6379;
                    $timeout = $this->redis->getTimeout() ?: 0.0;
                    $persistentId = $this->redis->getPersistentID();
                    $auth = $this->redis->getAuth();
                    $dbNum = $this->redis->getDBNum();

                    // Create a completely new Redis instance to avoid closing the shared singleton reference
                    $newRedis = $this->redisFactory ? ($this->redisFactory)() : new Redis();

                    if ($persistentId) {
                        @$newRedis->pconnect($host, $port, $timeout, $persistentId);
                    } else {
                        @$newRedis->connect($host, $port, $timeout);
                    }

                    if ($auth) {
                        @$newRedis->auth($auth);
                    }
                    @$newRedis->select($dbNum);

                    // Replace current reference
                    $this->redis = $newRedis;
                }
            } catch (\Throwable) {
                // Fail silently and let the operation attempt execution
            }
            $this->connPid = $currentPid;
        }
    }
}
