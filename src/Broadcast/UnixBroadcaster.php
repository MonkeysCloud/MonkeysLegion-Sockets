<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Broadcast;

use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Sockets\Contracts\MessageInterface;
use JsonException;
use RuntimeException;

/**
 * UnixBroadcaster
 * 
 * High-performance, low-latency broadcaster for single-server setups.
 * Communicates with the WebSocket worker via a Unix Domain Socket (.sock file).
 */
class UnixBroadcaster implements BroadcasterInterface
{
    private ?string $currentTarget = null;
    private ?string $targetType = null;

    public function __construct(
        private readonly string $socketPath
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

    public function emit(string $event, mixed $data = []): void
    {
        $this->publish($event, $data);
    }

    public function raw(MessageInterface|string $message): void
    {
        $payload = $message instanceof MessageInterface ? $message->getPayload() : $message;
        $this->publish('raw', $payload);
    }

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

            $payload = \json_encode($envelope, JSON_THROW_ON_ERROR);
            
            $this->sendToSocket($payload);

        } catch (JsonException $e) {
            throw new RuntimeException("UnixBroadcaster failed to encode payload: " . $e->getMessage(), 0, $e);
        } finally {
            $this->reset();
        }
    }

    private function sendToSocket(string $payload): void
    {
        if (!\file_exists($this->socketPath)) {
            throw new RuntimeException("WebSocket IPC socket does not exist at: {$this->socketPath}");
        }

        $socket = @\fsockopen('unix://' . $this->socketPath);
        if (!$socket) {
            throw new RuntimeException("Could not connect to WebSocket IPC socket: {$this->socketPath}");
        }

        // We append a delimiter or just write the payload. 
        // For Unix sockets, a simple write followed by close often works if the server reads until EOF.
        // However, we'll use a newline delimiter for stream safety in long-lived connections.
        @\fwrite($socket, $payload . "\n");
        @\fclose($socket);
    }

    private function reset(): void
    {
        $this->targetType = null;
        $this->currentTarget = null;
    }
}
