<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Protocol\JsonFormatter;
use RuntimeException;

final class JsonFormatterTest extends TestCase
{
    #[Test]
    public function it_formats_data_to_valid_json(): void
    {
        $formatter = new JsonFormatter();
        $json = $formatter->format('test_event', ['foo' => 'bar'], ['user_id' => 123]);

        $decoded = \json_decode($json, true);
        $this->assertEquals('test_event', $decoded['event']);
        $this->assertEquals('bar', $decoded['data']['foo']);
        $this->assertEquals(123, $decoded['meta']['user_id']);
        $this->assertArrayHasKey('t', $decoded['meta']);
    }

    #[Test]
    public function it_parses_json_to_structured_array(): void
    {
        $formatter = new JsonFormatter();
        $payload = \json_encode([
            'event' => 'ping',
            'data' => 'pong',
            'meta' => ['x' => 'y']
        ]);

        $result = $formatter->parse($payload);

        $this->assertEquals('ping', $result['event']);
        $this->assertEquals('pong', $result['data']);
        $this->assertEquals('y', $result['meta']['x']);
    }

    #[Test]
    public function it_normalizes_missing_fields_during_parse(): void
    {
        $formatter = new JsonFormatter();
        $result = $formatter->parse('{}');

        $this->assertEquals('unknown', $result['event']);
        $this->assertNull($result['data']);
        $this->assertIsArray($result['meta']);
    }

    #[Test]
    public function it_throws_exception_on_format_failure(): void
    {
        $formatter = new JsonFormatter();
        
        // Circular reference
        $data = [];
        $data['self'] = &$data;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to format JSON payload');
        
        $formatter->format('fail', $data);
    }

    #[Test]
    public function it_throws_exception_on_parse_failure(): void
    {
        $formatter = new JsonFormatter();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse JSON payload');
        
        $formatter->parse('INVALID_JSON{]]}');
    }
}
