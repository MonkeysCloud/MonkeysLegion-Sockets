<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Broadcast;

use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\MessageSerializerInterface;
use MonkeysLegion\Sockets\Frame\Frame;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * RedisBridge
 * 
 * Orchestrates the reception of messages from the Redis Pub/Sub channel 
 * and distributes them to the appropriate local connections.
 */
class BroadcastBridge
{
    public function __construct(
        private readonly ConnectionRegistryInterface $registry,
        private readonly MessageSerializerInterface $serializer,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    /**
     * Handle an incoming message from the Redis channel.
     */
    public function handle(string $payload): void
    {
        try {
            $envelope = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            
            $type = $envelope['type'] ?? 'broadcast';
            $target = $envelope['target'] ?? null;
            $event = $envelope['event'] ?? 'message';
            $data = $envelope['data'] ?? [];

            // 1. Resolve outgoing message (Skip serialization for raw events)
            $message = ($event === 'raw') 
                ? (string) $data 
                : $this->serializer->serialize($event, $data);

            // 2. Dispatch based on type
            match ($type) {
                'broadcast' => $this->broadcast($message),
                'tag' => $this->tagBroadcast($target, $message),
                'connection' => $this->directSend($target, $message),
                default => $this->logger->warning("Unknown bridge message type: $type"),
            };

        } catch (\Throwable $e) {
            $this->logger->error("BroadcastBridge failed to process payload: " . $e->getMessage());
        }
    }

    private function broadcast(string $payload): void
    {
        foreach ($this->registry->all() as $connection) {
            $connection->send($payload);
        }
    }

    private function tagBroadcast(string $tag, string $payload): void
    {
        foreach ($this->registry->getByTag($tag) as $connection) {
            $connection->send($payload);
        }
    }

    private function directSend(string $id, string $payload): void
    {
        $connection = $this->registry->get($id);
        $connection?->send($payload);
    }
}
