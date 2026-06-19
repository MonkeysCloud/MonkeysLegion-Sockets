<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Broadcast\Subscriber;

use MonkeysLegion\Sockets\Broadcast\BroadcastBridge;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use MonkeysLegion\Sockets\Driver\ReactSocketDriver;
use MonkeysLegion\Mlc\Config;
use React\EventLoop\Loop;

/**
 * Wires the broadcast subscriber into the ReactPHP event loop.
 *
 * Unix: listens on a non-blocking Unix socket, accumulating chunks until EOF
 *       before dispatching to the bridge (fixes the fgets() race condition).
 *
 * Redis: forks a child process that subscribes to Redis pub/sub and pipes
 *        messages back to the parent via a socket-pair; auto-respawns on
 *        child death.
 */
final class ReactSubscriberWiring
{
    public function __construct(
        private readonly ReactSocketDriver $driver,
        private readonly Config $config,
        private readonly ?RedisClientInterface $redis
    ) {}

    public function wire(BroadcastBridge $bridge): void
    {
        $broadcastVal = $this->config->get('sockets.broadcast', 'redis');
        $broadcast    = \is_string($broadcastVal) ? $broadcastVal : 'redis';

        match ($broadcast) {
            'unix'  => $this->wireUnix($bridge),
            'redis' => $this->wireRedis($bridge),
            default => null,
        };
    }

    // -------------------------------------------------------------------------

    private function wireUnix(BroadcastBridge $bridge): void
    {
        $configPath = $this->config->get('sockets.unix.path', '/tmp/ml_sockets.sock');
        $socketPath = \is_string($configPath) ? $configPath : '/tmp/ml_sockets.sock';

        if (\file_exists($socketPath)) {
            @\unlink($socketPath);
        }

        $unixServer = @\stream_socket_server('unix://' . $socketPath, $errno, $errstr);
        if (!$unixServer) {
            return;
        }

        @\chmod($socketPath, 0666);
        \stream_set_blocking($unixServer, false);

        Loop::addReadStream($unixServer, function ($unixServer) use ($bridge): void {
            $conn = @\stream_socket_accept($unixServer);
            if (!$conn) {
                return;
            }

            \stream_set_blocking($conn, false);

            // Accumulate chunks until EOF — fixes the fgets() race condition
            // where a non-blocking read may return empty before the sender
            // has flushed the write buffer.
            $buffer = '';
            Loop::addReadStream($conn, function ($conn) use (&$buffer, $bridge): void {
                $chunk = @\fread($conn, 8192);
                if ($chunk === false || $chunk === '') {
                    Loop::removeReadStream($conn);
                    @\fclose($conn);
                    if ($buffer !== '') {
                        $bridge->handle(\trim($buffer));
                    }
                    return;
                }
                $buffer .= $chunk;
            });
        });
    }

    private function wireRedis(BroadcastBridge $bridge): void
    {
        $spawnChild = null;
        $spawnChild = function () use (&$spawnChild, $bridge): void {
            $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                return;
            }

            [$parentSocket, $childSocket] = $pair;
            $pid = \pcntl_fork();

            if ($pid === 0) {
                // ── Child process ──
                @\fclose($parentSocket);
                try {
                    $this->driver->stop();
                } catch (\Throwable) {
                }

                if ($this->redis) {
                    $this->reconnectRedisInChild();

                    $configChannel = $this->config->get('sockets.redis.channel', 'ml_sockets:broadcast');
                    $channel       = \is_string($configChannel) ? $configChannel : 'ml_sockets:broadcast';

                    $subscriber = new \MonkeysLegion\Sockets\Broadcast\RedisSubscriber($this->redis);
                    try {
                        $subscriber->subscribe([$channel], static function (string $message) use ($childSocket): void {
                            @\fwrite($childSocket, $message . "\n");
                        });
                    } catch (\Throwable) {
                        exit(1);
                    }
                }
                exit(0);
            }

            // ── Parent process ──
            @\fclose($childSocket);
            \stream_set_blocking($parentSocket, false);

            Loop::addReadStream($parentSocket, function ($parentSocket) use ($bridge, &$spawnChild): void {
                if (\feof($parentSocket) || ($payload = \fgets($parentSocket)) === false) {
                    Loop::removeReadStream($parentSocket);
                    @\fclose($parentSocket);
                    Loop::addTimer(1.0, static function () use ($spawnChild): void {
                        ($spawnChild)();
                    });
                    return;
                }
                $bridge->handle(\trim($payload));
            });
        };

        $spawnChild();
    }

    private function reconnectRedisInChild(): void
    {
        if (!$this->redis) {
            return;
        }
        try {
            $ref = new \ReflectionClass($this->redis);
            if (!$ref->hasProperty('redis')) {
                return;
            }
            $prop     = $ref->getProperty('redis');
            $prop->setAccessible(true);
            $redisObj = $prop->getValue($this->redis);
            if ($redisObj instanceof \Redis) {
                $host = $redisObj->getHost() ?: '127.0.0.1';
                $port = $redisObj->getPort() ?: 6379;
                @$redisObj->close();
                @$redisObj->connect($host, $port, 0.5);
            }
        } catch (\Throwable) {
        }
    }
}
