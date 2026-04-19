<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Broadcast;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Broadcast\RedisSubscriber;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use Psr\Log\LoggerInterface;
use Exception;

final class RedisSubscriberTest extends TestCase
{
    #[Test]
    public function it_delegates_subscription_to_client_and_dispatches_messages(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $subscriber = new RedisSubscriber($redis);

        $capturedMessage = null;
        $handler = function($msg) use (&$capturedMessage) {
            $capturedMessage = $msg;
        };

        // Simulate the phpredis subscribe callback behavior
        $redis->expects($this->once())
            ->method('subscribe')
            ->with(['chan1'], $this->callback(function($cb) use ($capturedMessage) {
                // Execute the callback once to simulate an incoming message
                $cb(null, 'chan1', 'hello_world');
                return true;
            }));

        $subscriber->subscribe(['chan1'], $handler);
        
        $this->assertEquals('hello_world', $capturedMessage);
    }

    #[Test]
    public function it_logs_and_returns_on_handler_failure_without_crashing_loop(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = new RedisSubscriber($redis, $logger);

        $redis->expects($this->once())
            ->method('subscribe')
            ->with(['chan2'], $this->callback(function($cb) {
                $cb(null, 'chan2', 'bad_msg');
                return true;
            }));

        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error in Redis subscription handler: CRASH'));

        $subscriber->subscribe(['chan2'], function() {
            throw new Exception('CRASH');
        });
    }

    #[Test]
    public function it_rethrows_critical_redis_errors(): void
    {
        $redis = $this->createStub(RedisClientInterface::class);
        $subscriber = new RedisSubscriber($redis);

        $redis->method('subscribe')->willThrowException(new Exception('REDIS_DOWN'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('REDIS_DOWN');

        $subscriber->subscribe(['chan3'], fn() => null);
    }

    #[Test]
    public function it_tracks_running_state(): void
    {
        $redis = $this->createStub(RedisClientInterface::class);
        $subscriber = new RedisSubscriber($redis);

        $this->assertFalse($subscriber->isRunning());

        $redis->method('subscribe')->willReturnCallback(function($ch, $cb) use ($subscriber) {
            $this->assertTrue($subscriber->isRunning());
            return false;
        });

        $subscriber->subscribe(['chan4'], fn() => null);
        $this->assertFalse($subscriber->isRunning());
    }

    #[Test]
    public function it_can_be_stopped(): void
    {
        $redis = $this->createStub(RedisClientInterface::class);
        $subscriber = new RedisSubscriber($redis);

        $subscriber->stop();
        $this->assertFalse($subscriber->isRunning());
    }
}
