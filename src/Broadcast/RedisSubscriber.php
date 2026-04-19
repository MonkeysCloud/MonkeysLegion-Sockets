<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Broadcast;

use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * RedisSubscriber
 * 
 * Manages the blocking Redis subscription loop and dispatches 
 * incoming signals to the provided handler (typically the RedisBridge).
 */
class RedisSubscriber
{
    private bool $running = false;

    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    /**
     * Start the subscription loop on the given channels.
     * Note: This is a BLOCKING operation. It should be run in a separate 
     * process or thread (e.g., via pcntl_fork or a Swoole Process).
     * 
     * @param string[] $channels
     */
    public function subscribe(array $channels, callable $handler): void
    {
        $this->running = true;

        $this->logger->info("Starting Redis Subscriber on channels: " . \implode(', ', $channels));

        try {
            $this->redis->subscribe($channels, function ($redis, $channel, $message) use ($handler) {
                try {
                    $handler($message, $channel);
                } catch (Throwable $e) {
                    $this->logger->error("Error in Redis subscription handler: " . $e->getMessage());
                }

                // Internal mechanism to stop the loop if needed (based on phpredis behavior)
                return $this->running;
            });

            $this->running = false;
        } catch (Throwable $e) {
            $this->logger->critical("Redis Subscriber crashed: " . $e->getMessage());
            $this->running = false;
            throw $e;
        }
    }

    /**
     * Stop the subscription loop gracefully.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->logger->info("Stopping Redis Subscriber...");
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
