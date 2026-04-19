<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Broadcast;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Broadcast\UnixBroadcaster;
use MonkeysLegion\Sockets\Broadcast\UnixSubscriber;
use MonkeysLegion\Sockets\Broadcast\BroadcastBridge;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\MessageSerializerInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;

final class UnixIpcIntegrationTest extends TestCase
{
    private string $socketPath;

    protected function setUp(): void
    {
        $this->socketPath = \sys_get_temp_dir() . '/ml_test_' . \uniqid() . '.sock';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->socketPath)) {
            @\unlink($this->socketPath);
        }
    }

    #[Test]
    public function it_successfully_broadcasts_via_unix_socket(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $registry = $this->createStub(ConnectionRegistryInterface::class);
        $serializer = $this->createStub(MessageSerializerInterface::class);
        $conn = $this->createStub(ConnectionInterface::class);

        $tempResultFile = \sys_get_temp_dir() . '/ipc_result_' . \uniqid();
        if (\file_exists($tempResultFile)) \unlink($tempResultFile);

        $serializer->method('serialize')->willReturn('IPC_DATA');
        $registry->method('all')->willReturn([$conn]);

        // We use the stub to write to a file since mocks don't share state between processes
        $conn->method('send')
            ->willReturnCallback(function() use ($tempResultFile) {
                \file_put_contents($tempResultFile, 'SUCCESS');
            });

        $pid = \pcntl_fork();

        if ($pid === 0) {
            // Child: Subscriber
            try {
                $bridge = new BroadcastBridge($registry, $serializer);
                $subscriber = new UnixSubscriber($this->socketPath);
                
                // Add a small delay to ensure the broadcaster can connect
                $subscriber->listen(function($payload) use ($bridge, $subscriber) {
                    $bridge->handle($payload);
                    $subscriber->stop();
                });
            } catch (\Throwable $e) {
                \file_put_contents($tempResultFile, 'ERROR: ' . $e->getMessage());
            }
            exit(0);
        }

        // Parent: Broadcaster
        \usleep(100000); // Wait for subscriber to create socket

        try {
            $broadcaster = new UnixBroadcaster($this->socketPath);
            $broadcaster->emit('test_event', ['ping' => 'pong']);
        } catch (\Throwable $e) {
            $this->fail("Broadcaster failed: " . $e->getMessage());
        }

        // Wait for result
        for ($i = 0; $i < 10; $i++) {
            if (\file_exists($tempResultFile)) break;
            \usleep(100000);
        }

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);

        $result = @\file_get_contents($tempResultFile);
        if (\file_exists($tempResultFile)) \unlink($tempResultFile);

        $this->assertEquals('SUCCESS', $result, "IPC message was not received by subscriber");
    }
}
