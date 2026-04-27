<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Protocol\MsgPackFormatter;
use RuntimeException;

final class MsgPackFormatterTest extends TestCase
{
    #[Test]
    public function it_formats_data_to_valid_msgpack(): void
    {
        $formatter = new MsgPackFormatter();
        $binary = $formatter->format('test_event', ['foo' => 'bar'], ['id' => 1]);

        // Basic verification that it's binary and contains our data
        $this->assertIsString($binary);
        // MessagePack has headers, so we check if we can parse it back
        $decoded = $formatter->parse($binary);
        
        $this->assertEquals('test_event', $decoded['event']);
        $this->assertEquals('bar', $decoded['data']['foo']);
        $this->assertEquals(1, $decoded['meta']['id']);
        $this->assertArrayHasKey('t', $decoded['meta']);
    }

    #[Test]
    public function it_normalizes_missing_fields_during_parse(): void
    {
        $formatter = new MsgPackFormatter();
        // Pack an empty map
        $binary = (new \MessagePack\Packer())->pack([]);
        
        $result = $formatter->parse($binary);

        $this->assertEquals('unknown', $result['event']);
        $this->assertNull($result['data']);
        $this->assertIsArray($result['meta']);
    }

    #[Test]
    public function it_throws_exception_on_parse_failure(): void
    {
        $formatter = new MsgPackFormatter();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse MessagePack payload');
        
        // This should definitely fail if it's not valid MessagePack
        $formatter->parse("\xC1"); // \xC1 is a reserved/never-used byte in MsgPack
    }

    #[Test]
    public function it_throws_exception_on_format_failure(): void
    {
        $formatter = new MsgPackFormatter();

        // Resources are not serializable by MessagePack
        $resource = fopen('php://memory', 'r');
        $data = ['handle' => $resource];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to format MessagePack payload');

        try {
            $formatter->format('fail', $data);
        } finally {
            fclose($resource);
        }
    }
}
