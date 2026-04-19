<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\StreamConnection;
use MonkeysLegion\Sockets\Frame\FrameProcessor;

#[CoversClass(StreamConnection::class)]
final class StreamConnectionTest extends TestCase
{
    private $stream;
    
    protected function setUp(): void
    {
        $this->stream = \fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
    }

    #[Test]
    public function it_buffers_send_data(): void
    {
        $processor = new FrameProcessor();
        $connection = new StreamConnection($this->stream, 'id', $processor);

        $connection->send('Hello');
        
        // Data should be in buffer, not in streamyet (because we haven't flushed)
        $this->assertTrue($connection->hasPendingWrites());
        
        \rewind($this->stream);
        $this->assertSame('', \stream_get_contents($this->stream));
    }

    #[Test]
    public function it_flushes_buffered_data(): void
    {
        $processor = new FrameProcessor();
        $connection = new StreamConnection($this->stream, 'id', $processor);

        $connection->send('Hello');
        $written = $connection->flush();
        
        $this->assertGreaterThan(2, $written); // 2 bytes header + 'Hello'
        $this->assertFalse($connection->hasPendingWrites());
        
        \rewind($this->stream);
        $this->assertStringContainsString('Hello', \stream_get_contents($this->stream));
    }

    #[Test]
    public function it_enforces_backpressure_memory_limit(): void
    {
        $processor = new FrameProcessor();
        $connection = new StreamConnection($this->stream, 'id', $processor);

        // Max is 5MB. Let's send 6MB.
        $largePayload = \str_repeat('A', 6 * 1024 * 1024);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backpressure limit exceeded');

        $connection->send($largePayload);
    }
}
