<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Driver\ReactConnection;
use MonkeysLegion\Sockets\Frame\FrameProcessor;

/**
 * ReactConnectionTest
 *
 * Unit tests for ReactConnection's metadata handling.
 */
final class ReactConnectionTest extends TestCase
{
    #[Test]
    public function it_returns_empty_metadata_by_default(): void
    {
        $raw        = $this->createStub(\React\Socket\ConnectionInterface::class);
        $connection = new ReactConnection($raw, new FrameProcessor());

        $this->assertSame([], $connection->getMetadata());
    }

    #[Test]
    public function it_merges_metadata_on_set(): void
    {
        $raw        = $this->createStub(\React\Socket\ConnectionInterface::class);
        $connection = new ReactConnection($raw, new FrameProcessor());

        $connection->setMetadata(['get' => ['token' => 'abc123'], 'uri' => '/ws?token=abc123']);

        $meta = $connection->getMetadata();
        $this->assertSame('abc123', $meta['get']['token'] ?? null);
        $this->assertSame('/ws?token=abc123', $meta['uri'] ?? null);
    }

    #[Test]
    public function it_merges_additional_metadata_without_overwriting_existing(): void
    {
        $raw        = $this->createStub(\React\Socket\ConnectionInterface::class);
        $connection = new ReactConnection($raw, new FrameProcessor());

        $connection->setMetadata(['get' => ['token' => 'abc']]);
        $connection->setMetadata(['headers' => ['Host' => ['example.com']]]);

        $meta = $connection->getMetadata();
        $this->assertArrayHasKey('get', $meta);
        $this->assertArrayHasKey('headers', $meta);
    }

    #[Test]
    public function it_starts_in_non_upgraded_state(): void
    {
        $raw        = $this->createStub(\React\Socket\ConnectionInterface::class);
        $connection = new ReactConnection($raw, new FrameProcessor());

        $this->assertFalse($connection->isUpgraded());
    }

    #[Test]
    public function it_transitions_to_upgraded_state(): void
    {
        $raw        = $this->createStub(\React\Socket\ConnectionInterface::class);
        $connection = new ReactConnection($raw, new FrameProcessor());

        $connection->setUpgraded(true);

        $this->assertTrue($connection->isUpgraded());
    }
}
