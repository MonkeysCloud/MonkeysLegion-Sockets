<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Console\Attributes\Command as CliCommand;
use MonkeysLegion\Cli\Console\Traits\Cli;
use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Mlc\Config;

/**
 * SocketServerCommand
 * 
 * Production-ready console command to manage the MonkeysLegion WebSocket cluster.
 * 
 * Signature: socket:serve {action=start} [--host=] [--port=]
 */
#[CliCommand('socket:serve', 'Start the MonkeysLegion WebSocket Server cluster')]
class SocketServerCommand extends Command
{
    use Cli;

    public function __construct(
        private readonly DriverInterface $driver,
        private readonly Config $config,
        private readonly \MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface $registry,
        private readonly ?\MonkeysLegion\Sockets\Server\WebSocketServer $webSocketServer = null,
        private readonly ?\Psr\Container\ContainerInterface $container = null,
        private readonly ?\MonkeysLegion\Sockets\Contracts\RedisClientInterface $redis = null
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $action = $this->argument(0) ?? 'start';

        if ($action !== 'start') {
            $this->cliLine()
                ->error("Action [$action] not supported yet.")
                ->space()
                ->muted("Currently only [start] is implemented.")
                ->printError();
                
            return self::FAILURE;
        }

        $host = $this->option('host');
        $port = $this->option('port');

        // Derive final bind settings from injected driver's config
        $selectedDriver = $this->config->get('sockets.driver', 'stream');
        
        $configHost = $this->config->get("sockets.host", "0.0.0.0");
        $finalHost = \is_string($host) ? $host : (\is_string($configHost) ? $configHost : "0.0.0.0");
        
        $portVal = \is_string($port) || \is_int($port) ? (int) $port : null;
        $configPort = $this->config->get("sockets.port", 8080);
        $finalPort = $portVal ?? (\is_int($configPort) ? $configPort : 8080);

        $this->cliLine()
            ->add("🚀 Starting MonkeysLegion WebSocket Server...", "bright_white", "bold")
            ->print();

        $this->cliLine()
            ->add("📡 Driver: ", "white")
            ->add(\get_class($this->driver), "cyan")
            ->print();

        $this->cliLine()
            ->add("🔗 Bind:   ", "white")
            ->add("$finalHost:$finalPort", "bright_yellow")
            ->print();

        $this->cliLine()
            ->add("🛠️ Mode:   ", "white")
            ->add("Production", "bright_green")
            ->print();

        $this->cliLine()
            ->muted(str_repeat('-', 50))
            ->print();

        // 1. Setup Signal Handling for Graceful Shutdown
        if (!($this->driver instanceof \MonkeysLegion\Sockets\Driver\SwooleDriver) && \extension_loaded('pcntl')) {
            \pcntl_async_signals(true);
            $shutdown = function () {
                $this->cliLine()
                    ->space()
                    ->add("🛑 Shutting down the server gracefully...", "bright_red", "bold")
                    ->print();
                
                $this->driver->stop();
                exit(0);
            };

            \pcntl_signal(SIGINT, $shutdown);
            \pcntl_signal(SIGTERM, $shutdown);
        }

        $bridge = new \MonkeysLegion\Sockets\Broadcast\BroadcastBridge(
            $this->registry,
            new \MonkeysLegion\Sockets\Serialization\JsonMessageSerializer()
        );
        $this->bootstrapSubscriber($bridge);

        if ($this->webSocketServer && $this->container && $this->container->has(\MonkeysLegion\Sockets\Contracts\SocketServerBootstrapInterface::class)) {
            $bootstrap = $this->container->get(\MonkeysLegion\Sockets\Contracts\SocketServerBootstrapInterface::class);
            if ($bootstrap instanceof \MonkeysLegion\Sockets\Contracts\SocketServerBootstrapInterface) {
                $bootstrap->boot($this->driver, $this->webSocketServer);
            }
        }

        try {
            $this->driver->listen($finalHost, $finalPort);
        } catch (\Throwable $e) {
            $this->cliLine()
                ->error("Failed to start server: ")
                ->add($e->getMessage(), "white")
                ->printError();
                
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function bootstrapSubscriber(\MonkeysLegion\Sockets\Broadcast\BroadcastBridge $bridge): void
    {
        $broadcastVal = $this->config->get('sockets.broadcast', 'redis');
        $broadcast = \is_string($broadcastVal) ? $broadcastVal : 'redis';

        if ($this->driver instanceof \MonkeysLegion\Sockets\Driver\SwooleDriver) {
            $this->driver->onIpcMessage(function (string $message) use ($bridge) {
                $bridge->handle($message);
            });

            $this->driver->registerIpcProcess(function ($server) use ($broadcast) {
                $handler = function (string $payload) use ($server) {
                    $workerNum = $server->setting['worker_num'] ?? 1;
                    for ($i = 0; $i < $workerNum; $i++) {
                        $server->sendMessage($payload, $i);
                    }
                };

                if ($broadcast === 'unix') {
                    $configPath = $this->config->get('sockets.unix.path', '/tmp/ml_sockets.sock');
                    $socketPath = \is_string($configPath) ? $configPath : '/tmp/ml_sockets.sock';
                    $subscriber = new \MonkeysLegion\Sockets\Broadcast\UnixSubscriber($socketPath);
                    $subscriber->listen($handler);
                } elseif ($broadcast === 'redis') {
                    $configChannel = $this->config->get('sockets.redis.channel', 'ml_sockets:broadcast');
                    $channel = \is_string($configChannel) ? $configChannel : 'ml_sockets:broadcast';
                    if ($this->redis) {
                        $subscriber = new \MonkeysLegion\Sockets\Broadcast\RedisSubscriber($this->redis);
                        $subscriber->subscribe([$channel], $handler);
                    }
                }
            });
            return;
        }

        if ($this->driver instanceof \MonkeysLegion\Sockets\Driver\ReactSocketDriver) {
            if ($broadcast === 'unix') {
                $configPath = $this->config->get('sockets.unix.path', '/tmp/ml_sockets.sock');
                $socketPath = \is_string($configPath) ? $configPath : '/tmp/ml_sockets.sock';
                if (\file_exists($socketPath)) {
                    @\unlink($socketPath);
                }
                $unixServer = @\stream_socket_server('unix://' . $socketPath, $errno, $errstr);
                if ($unixServer) {
                    @\chmod($socketPath, 0666);
                    \stream_set_blocking($unixServer, false);
                    \React\EventLoop\Loop::addReadStream($unixServer, function ($unixServer) use ($bridge) {
                        $conn = @\stream_socket_accept($unixServer);
                        if ($conn) {
                            \stream_set_blocking($conn, false);
                            \React\EventLoop\Loop::addReadStream($conn, function ($conn) use ($bridge) {
                                $payload = \fgets($conn);
                                if ($payload) {
                                    $bridge->handle(\trim($payload));
                                }
                                \React\EventLoop\Loop::removeReadStream($conn);
                                @\fclose($conn);
                            });
                        }
                    });
                }
            } elseif ($broadcast === 'redis') {
                $spawnRedisSubscriber = function () use (&$spawnRedisSubscriber, $bridge) {
                    $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                    if ($pair === false) {
                        return;
                    }
                    [$parentSocket, $childSocket] = $pair;
                    
                    $pid = \pcntl_fork();
                    if ($pid === 0) {
                        @\fclose($parentSocket);
                        try {
                            $this->driver->stop();
                        } catch (\Throwable) {
                        }
                        if ($this->redis) {
                            try {
                                $refObj = new \ReflectionClass($this->redis);
                                if ($refObj->hasProperty('redis')) {
                                    $prop = $refObj->getProperty('redis');
                                    $prop->setAccessible(true);
                                    $redisObj = $prop->getValue($this->redis);
                                    if ($redisObj instanceof \Redis) {
                                        $host = $redisObj->getHost() ?: '127.0.0.1';
                                        $port = $redisObj->getPort() ?: 6379;
                                        @$redisObj->close();
                                        @$redisObj->connect($host, $port, 0.5);
                                    }
                                }
                            } catch (\Throwable) {
                            }

                            $configChannel = $this->config->get('sockets.redis.channel', 'ml_sockets:broadcast');
                            $channel = \is_string($configChannel) ? $configChannel : 'ml_sockets:broadcast';
                            $subscriber = new \MonkeysLegion\Sockets\Broadcast\RedisSubscriber($this->redis);
                            try {
                                $subscriber->subscribe([$channel], function ($message) use ($childSocket) {
                                    @\fwrite($childSocket, $message . "\n");
                                });
                            } catch (\Throwable) {
                                exit(1);
                            }
                        }
                        exit(0);
                    }
                    
                    @\fclose($childSocket);
                    \stream_set_blocking($parentSocket, false);
                    
                    \React\EventLoop\Loop::addReadStream($parentSocket, function ($parentSocket) use ($bridge, &$spawnRedisSubscriber) {
                        if (\feof($parentSocket) || ($payload = \fgets($parentSocket)) === false) {
                            \React\EventLoop\Loop::removeReadStream($parentSocket);
                            @\fclose($parentSocket);
                            \React\EventLoop\Loop::addTimer(1.0, function () use ($spawnRedisSubscriber) {
                                $spawnRedisSubscriber();
                            });
                            return;
                        }
                        $bridge->handle(\trim($payload));
                    });
                };
                $spawnRedisSubscriber();
            }
            return;
        }

        if ($this->driver instanceof \MonkeysLegion\Sockets\Driver\StreamSocketDriver) {
            if ($broadcast === 'unix') {
                $configPath = $this->config->get('sockets.unix.path', '/tmp/ml_sockets.sock');
                $socketPath = \is_string($configPath) ? $configPath : '/tmp/ml_sockets.sock';
                if (\file_exists($socketPath)) {
                    @\unlink($socketPath);
                }
                $unixServer = @\stream_socket_server('unix://' . $socketPath, $errno, $errstr);
                if ($unixServer) {
                    @\chmod($socketPath, 0666);
                    \stream_set_blocking($unixServer, false);
                    $this->driver->addStream($unixServer, function ($unixServer) use ($bridge) {
                        $conn = @\stream_socket_accept($unixServer);
                        if ($conn) {
                            \stream_set_blocking($conn, false);
                            $this->driver->addStream($conn, function ($conn) use ($bridge) {
                                $payload = \fgets($conn);
                                if ($payload) {
                                    $bridge->handle(\trim($payload));
                                }
                                $this->driver->removeStream($conn);
                                @\fclose($conn);
                            });
                        }
                    });
                }
            } elseif ($broadcast === 'redis') {
                $spawnRedisSubscriber = function () use (&$spawnRedisSubscriber, $bridge) {
                    $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                    if ($pair === false) {
                        return;
                    }
                    [$parentSocket, $childSocket] = $pair;
                    
                    $pid = \pcntl_fork();
                    if ($pid === 0) {
                        @\fclose($parentSocket);
                        try {
                            $this->driver->stop();
                        } catch (\Throwable) {
                        }
                        if ($this->redis) {
                            try {
                                $refObj = new \ReflectionClass($this->redis);
                                if ($refObj->hasProperty('redis')) {
                                    $prop = $refObj->getProperty('redis');
                                    $prop->setAccessible(true);
                                    $redisObj = $prop->getValue($this->redis);
                                    if ($redisObj instanceof \Redis) {
                                        $host = $redisObj->getHost() ?: '127.0.0.1';
                                        $port = $redisObj->getPort() ?: 6379;
                                        @$redisObj->close();
                                        @$redisObj->connect($host, $port, 0.5);
                                    }
                                }
                            } catch (\Throwable) {
                            }

                            $configChannel = $this->config->get('sockets.redis.channel', 'ml_sockets:broadcast');
                            $channel = \is_string($configChannel) ? $configChannel : 'ml_sockets:broadcast';
                            $subscriber = new \MonkeysLegion\Sockets\Broadcast\RedisSubscriber($this->redis);
                            try {
                                $subscriber->subscribe([$channel], function ($message) use ($childSocket) {
                                    @\fwrite($childSocket, $message . "\n");
                                });
                            } catch (\Throwable) {
                                exit(1);
                            }
                        }
                        exit(0);
                    }
                    
                    @\fclose($childSocket);
                    \stream_set_blocking($parentSocket, false);
                    
                    $this->driver->addStream($parentSocket, function ($parentSocket) use ($bridge, &$spawnRedisSubscriber) {
                        if (\feof($parentSocket) || ($payload = \fgets($parentSocket)) === false) {
                            $this->driver->removeStream($parentSocket);
                            @\fclose($parentSocket);
                            \sleep(1);
                            $spawnRedisSubscriber();
                            return;
                        }
                        $bridge->handle(\trim($payload));
                    });
                };
                $spawnRedisSubscriber();
            }
            return;
        }
    }
}
