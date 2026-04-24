<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Broadcast;

use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Sockets\Contracts\MessageInterface;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use JsonException;
use RuntimeException;

/**
 * RedisBroadcaster
 * 
 * Implementation of BroadcasterInterface that uses Redis Pub/Sub 
 * to send messages from a 'web' (PHP-FPM/CLI) context to the WebSocket workers.
 */
class RedisBroadcaster implements BroadcasterInterface
{
    private ?string $currentTarget = null;
    private ?string $targetType = null;

    public function __construct(
        private readonly RedisClientInterface $redis,
        private string $channel = 'ml_sockets:broadcast'
    ) {}

    public function broadcast(MessageInterface|string $message): void
    {
        $this->raw($message);
    }

    public function to(string $tag): self
    {
        $this->targetType = 'tag';
        $this->currentTarget = $tag;
        return $this;
    }

    public function toConnection(string $connectionId): self
    {
        $this->targetType = 'connection';
        $this->currentTarget = $connectionId;
        return $this;
    }

    public function publicChannel(string $name): self
    {
        return $this->to("public:{$name}");
    }

    public function privateChannel(string $name): self
    {
        return $this->to("private:{$name}");
    }

    public function channel(string $pattern, array $parameters): self
    {
        $resolved = $pattern;

        foreach ($parameters as $key => $value) {
            $resolved = \str_replace('{' . $key . '}', (string) $value, $resolved);
        }

        // Check if any placeholders remain unreplaced
        if (\preg_match('/\{[a-zA-Z0-9_]+\}/', $resolved)) {
            throw new RuntimeException("Broadcaster pattern binding failed. Some placeholders in [$pattern] were not provided in parameters.");
        }

        return $this->to($resolved);
    }

    public function emit(string $event, mixed $data = []): void
    {
        $this->publish($event, $data);
    }

    public function raw(MessageInterface|string $message): void
    {
        $payload = $message instanceof MessageInterface ? $message->getPayload() : $message;
        $this->publish('raw', $payload);
    }

    /**
     * Internal publish logic with automatic state reset and error wrapping.
     */
    private function publish(string $event, mixed $data): void
    {
        $type = $this->targetType ?? 'broadcast';
        $target = $this->currentTarget;

        try {
            $envelope = [
                'type' => $type,
                'target' => $target,
                'event' => $event,
                'data' => $data,
                'timestamp' => \microtime(true),
            ];

            $this->redis->publish($this->channel, \json_encode($envelope, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            throw new RuntimeException("Broadcaster failed to encode payload: " . $e->getMessage(), 0, $e);
        } finally {
            $this->reset();
        }
    }

    /**
     * Reset the fluent target state to prevent accidental cross-broadcasting.
     */
    private function reset(): void
    {
        $this->targetType = null;
        $this->currentTarget = null;
    }
}
