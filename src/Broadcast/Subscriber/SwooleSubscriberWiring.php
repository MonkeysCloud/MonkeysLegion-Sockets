<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Broadcast\Subscriber;

use MonkeysLegion\Sockets\Broadcast\BroadcastBridge;
use MonkeysLegion\Sockets\Broadcast\RedisSubscriber;
use MonkeysLegion\Sockets\Broadcast\UnixSubscriber;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use MonkeysLegion\Sockets\Driver\SwooleDriver;
use MonkeysLegion\Mlc\Config;

/**
 * Wires the broadcast subscriber into a running Swoole server.
 *
 * The IPC process callback is deferred to listen() so $server is fully
 * initialised before any child process is forked.
 */
final class SwooleSubscriberWiring
{
    public function __construct(
        private readonly SwooleDriver $driver,
        private readonly Config $config,
        private readonly ?RedisClientInterface $redis
    ) {}

    public function wire(BroadcastBridge $bridge): void
    {
        $this->driver->onIpcMessage(static function (string $message) use ($bridge): void {
            $bridge->handle($message);
        });

        $broadcastVal = $this->config->get('sockets.broadcast', 'redis');
        $broadcast    = \is_string($broadcastVal) ? $broadcastVal : 'redis';

        $this->driver->registerIpcProcess(function ($server) use ($broadcast): void {
            $handler = static function (string $payload) use ($server): void {
                $workerNum = $server->setting['worker_num'] ?? 1;
                for ($i = 0; $i < $workerNum; $i++) {
                    $server->sendMessage($payload, $i);
                }
            };

            if ($broadcast === 'unix') {
                $configPath = $this->config->get('sockets.unix.path', '/tmp/ml_sockets.sock');
                $socketPath = \is_string($configPath) ? $configPath : '/tmp/ml_sockets.sock';
                $running = true;
                while ($running) {
                    try {
                        $subscriber = new UnixSubscriber($socketPath);
                        $subscriber->listen($handler);
                    } catch (\Throwable) {
                        if (isset($server->master_pid) && $server->master_pid === 0) {
                            $running = false;
                        }
                        \usleep(100000); // Sleep 100ms before retrying
                    }
                }
            } elseif ($broadcast === 'redis' && $this->redis) {
                $configChannel = $this->config->get('sockets.redis.channel', 'ml_sockets:broadcast');
                $channel       = \is_string($configChannel) ? $configChannel : 'ml_sockets:broadcast';
                $running = true;
                while ($running) {
                    try {
                        $subscriber = new RedisSubscriber($this->redis);
                        $subscriber->subscribe([$channel], $handler);
                    } catch (\Throwable) {
                        if (isset($server->master_pid) && $server->master_pid === 0) {
                            $running = false;
                        }
                        \usleep(500000); // Sleep 500ms before retrying to avoid CPU pegging
                    }
                }
            }
        });
    }
}
