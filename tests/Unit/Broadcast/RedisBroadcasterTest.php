<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Broadcast;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Broadcast\RedisBroadcaster;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use MonkeysLegion\Sockets\Contracts\MessageInterface;
use RuntimeException;

final class RedisBroadcasterTest extends TestCase
{
    private $redis;
    private $broadcaster;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(RedisClientInterface::class);
        $this->broadcaster = new RedisBroadcaster($this->redis);
    }

    #[Test]
    public function it_publishes_to_correct_channel_with_broadcast_type(): void
    {
        $this->redis->expects($this->once())
            ->method('publish')
            ->with('ml_sockets:broadcast', $this->callback(function ($json) {
                $data = \json_decode($json, true);
                return $data['type'] === 'broadcast' && $data['event'] === 'test.event';
            }));

        $this->broadcaster->emit('test.event', ['foo' => 'bar']);
    }

    #[Test]
    public function it_handles_targeted_connection_broadcast(): void
    {
        $this->redis->expects($this->once())
            ->method('publish')
            ->with('ml_sockets:broadcast', $this->callback(function ($json) {
                $data = \json_decode($json, true);
                return $data['type'] === 'connection' && $data['target'] === 'conn_123';
            }));

        $this->broadcaster->toConnection('conn_123')->emit('update');
    }

    #[Test]
    public function it_handles_tag_targeted_broadcast(): void
    {
        $this->redis->expects($this->once())
            ->method('publish')
            ->with('ml_sockets:broadcast', $this->callback(function ($json) {
                $data = \json_decode($json, true);
                return $data['type'] === 'tag' && $data['target'] === 'room_1';
            }));

        $this->broadcaster->to('room_1')->emit('chat');
    }

    #[Test]
    public function it_resets_state_after_broadcast(): void
    {
        // First targeted call
        $this->broadcaster->to('room_1');
        $this->broadcaster->emit('targeted');

        // Second call should be back to 'broadcast' type
        $this->redis->expects($this->once())
            ->method('publish')
            ->with($this->anything(), $this->callback(function ($json) {
                $data = \json_decode($json, true);
                return $data['type'] === 'broadcast' && $data['target'] === null;
            }));

        $this->broadcaster->emit('global');
    }

    #[Test]
    public function it_supports_raw_messages(): void
    {
        $this->redis->expects($this->once())
            ->method('publish')
            ->with($this->anything(), $this->callback(function ($json) {
                $data = \json_decode($json, true);
                return $data['event'] === 'raw' && $data['data'] === 'raw_payload';
            }));

        $this->broadcaster->raw('raw_payload');
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
    public function it_throws_meaningful_exception_on_json_failure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Broadcaster failed to encode payload');

        $redis = $this->createStub(RedisClientInterface::class);
        $broadcaster = new RedisBroadcaster($redis);

        // Circular reference to trigger JSON error
        $data = [];
        $data['self'] = &$data;

        $broadcaster->emit('bad_json', $data);
    }

    #[Test]
    public function it_supports_the_legacy_broadcast_method(): void
    {
        $this->redis->expects($this->once())
            ->method('publish')
            ->with($this->anything(), $this->callback(function ($json) {
                $data = \json_decode($json, true);
                return $data['type'] === 'broadcast' && $data['event'] === 'raw' && $data['data'] === 'legacy';
            }));

        $this->broadcaster->broadcast('legacy');
    }
}
