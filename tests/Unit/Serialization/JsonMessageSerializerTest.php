<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Serialization;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Serialization\JsonMessageSerializer;
use MonkeysLegion\Sockets\Serialization\MessageEnvelope;

#[CoversClass(JsonMessageSerializer::class)]
#[CoversClass(MessageEnvelope::class)]
final class JsonMessageSerializerTest extends TestCase
{
    private JsonMessageSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonMessageSerializer();
    }

    #[Test]
    public function it_serializes_to_json_envelope(): void
    {
        $json = $this->serializer->serialize('user.created', ['id' => 123, 'name' => 'Aykut']);
        
        $this->assertJson($json);
        $decoded = \json_decode($json, true);
        
        $this->assertSame('user.created', $decoded['event']);
        $this->assertSame(123, $decoded['data']['id']);
        $this->assertSame('Aykut', $decoded['data']['name']);
    }

    #[Test]
    public function it_unserializes_from_json_envelope(): void
    {
        $payload = \json_encode([
            'event' => 'ping',
            'data' => 45.0,
            'metadata' => ['trace' => 'abc']
        ], JSON_PRESERVE_ZERO_FRACTION);

        $envelope = $this->serializer->unserialize($payload);
        
        $this->assertInstanceOf(MessageEnvelope::class, $envelope);
        $this->assertSame('ping', $envelope->event);
        $this->assertSame(45.0, $envelope->data);
        $this->assertSame('abc', $envelope->metadata['trace']);
    }

    #[Test]
    public function it_throws_exception_on_invalid_json(): void
    {
        $this->expectException(\JsonException::class);
        $this->serializer->unserialize('{ invalid json }');
    }
}
