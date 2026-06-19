<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\StreamConnection;
use MonkeysLegion\Sockets\Frame\FrameProcessor;

/**
 * StreamConnectionTest
 *
 * Unit tests for StreamConnection metadata handling.
 */
final class StreamConnectionTest extends TestCase
{
    /** @var resource */
    private mixed $socket;

    protected function setUp(): void
    {
        // Use an in-process socket pair so we have a real resource handle
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        $this->socket = $pair[0];
        \fclose($pair[1]);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
    }

    #[Test]
    public function it_returns_empty_metadata_by_default(): void
    {
        $connection = new StreamConnection($this->socket, 'test-id', new FrameProcessor());

        $this->assertSame([], $connection->getMetadata());
    }

    #[Test]
    public function it_merges_metadata_set_after_construction(): void
    {
        $connection = new StreamConnection($this->socket, 'test-id', new FrameProcessor());

        $connection->setMetadata(['get' => ['token' => 'abc123'], 'uri' => '/ws?token=abc123']);

        $meta = $connection->getMetadata();
        $this->assertSame('abc123', $meta['get']['token'] ?? null);
        $this->assertSame('/ws?token=abc123', $meta['uri'] ?? null);
    }

    #[Test]
    public function it_merges_successive_setMetadata_calls_without_overwriting(): void
    {
        $connection = new StreamConnection($this->socket, 'test-id', new FrameProcessor());

        $connection->setMetadata(['get' => ['token' => 'abc']]);
        $connection->setMetadata(['headers' => ['Host' => ['example.com']]]);

        $meta = $connection->getMetadata();
        $this->assertArrayHasKey('get', $meta);
        $this->assertArrayHasKey('headers', $meta);
    }

    #[Test]
    public function it_preserves_constructor_metadata_when_merging(): void
    {
        $connection = new StreamConnection(
            $this->socket,
            'test-id',
            new FrameProcessor(),
            5242880,
            ['server' => 'localhost']
        );

        $connection->setMetadata(['get' => ['token' => 'xyz']]);

        $meta = $connection->getMetadata();
        $this->assertSame('localhost', $meta['server'] ?? null);
        $this->assertSame('xyz', $meta['get']['token'] ?? null);
    }
}
