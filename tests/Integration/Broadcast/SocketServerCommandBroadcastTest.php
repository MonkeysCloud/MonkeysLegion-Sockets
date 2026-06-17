<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Broadcast;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Cli\Command\SocketServerCommand;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;
use MonkeysLegion\Sockets\Broadcast\UnixBroadcaster;
use MonkeysLegion\Sockets\Broadcast\RedisBroadcaster;
use MonkeysLegion\Mlc\Config;

final class SocketServerCommandBroadcastTest extends TestCase
{
    private string $socketPath;

    protected function setUp(): void
    {
        $this->socketPath = \sys_get_temp_dir() . '/ml_serve_test_' . \uniqid() . '.sock';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->socketPath)) {
            @\unlink($this->socketPath);
        }
    }

    #[Test]
    public function it_starts_unix_subscriber_and_broadcasts_successfully(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $port = 9066;

        $registry = new ConnectionRegistry();
        $driver = new StreamSocketDriver();
        $driver->setRegistry($registry);

        $config = new Config([
            'sockets' => [
                'driver' => 'stream',
                'broadcast' => 'unix',
                'host' => '127.0.0.1',
                'port' => $port,
                'unix' => [
                    'path' => $this->socketPath,
                ],
            ],
        ]);

        $command = new SocketServerCommand(
            $driver,
            $config,
            $registry
        );

        global $argv;
        $oldArgv = $argv;
        $argv = ['bin/sockets', 'socket:serve', 'start', "--host=127.0.0.1", "--port=$port"];

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try {
                $command->__invoke();
            } catch (\Throwable) {
            }
            exit(0);
        }

        // Restore global argv
        $argv = $oldArgv;

        \usleep(500000);

        $client = \stream_socket_client("tcp://127.0.0.1:$port");
        if (!$client) {
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
            $this->fail("Could not connect to WebSocket server");
        }

        // Send handshake
        $request = "GET / HTTP/1.1\r\n" .
                   "Host: localhost\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";

        \fwrite($client, $request);
        \usleep(200000);
        $response = \fread($client, 2048);

        $this->assertIsString($response);
        $this->assertStringContainsString('101 Switching Protocols', $response);

        // Now publish message via UnixBroadcaster
        $broadcaster = new UnixBroadcaster($this->socketPath);
        $broadcaster->emit('test_event', ['hello' => 'world']);

        // Give it time to propagate from Broadcaster -> Unix Socket -> Command Subscriber -> WebSocket connection
        \usleep(300000);

        $websocketFrame = \fread($client, 2048);

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);
        @\fclose($client);

        $this->assertIsString($websocketFrame);
        $this->assertNotEmpty($websocketFrame);
        $this->assertStringContainsString('test_event', $websocketFrame);
        $this->assertStringContainsString('world', $websocketFrame);
    }

    #[Test]
    public function it_starts_redis_subscriber_and_broadcasts_successfully(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        $host = \getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (\getenv('REDIS_PORT') ?: 6379);

        try {
            $redis = new \Redis();
            if (!@$redis->connect($host, $port, 0.5)) {
                $this->markTestSkipped('Redis server not available at ' . $host . ':' . $port);
            }
        } catch (\RedisException $e) {
            $this->markTestSkipped('Redis connection failed: ' . $e->getMessage());
        }

        $serverPort = 9077;

        $registry = new ConnectionRegistry();
        $driver = new StreamSocketDriver();
        $driver->setRegistry($registry);

        $channel = 'ml_serve_test_' . \uniqid();

        $config = new Config([
            'sockets' => [
                'driver' => 'stream',
                'broadcast' => 'redis',
                'host' => '127.0.0.1',
                'port' => $serverPort,
                'redis' => [
                    'channel' => $channel,
                ],
            ],
        ]);

        $redisClient = new \MonkeysLegion\Sockets\Registry\PhpRedisClient($redis);

        $command = new SocketServerCommand(
            $driver,
            $config,
            $registry,
            $redisClient
        );

        global $argv;
        $oldArgv = $argv;
        $argv = ['bin/sockets', 'socket:serve', 'start', "--host=127.0.0.1", "--port=$serverPort"];

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try {
                $command->__invoke();
            } catch (\Throwable) {
            }
            exit(0);
        }

        // Restore global argv
        $argv = $oldArgv;

        \usleep(500000);

        $client = \stream_socket_client("tcp://127.0.0.1:$serverPort");
        if (!$client) {
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
            $this->fail("Could not connect to WebSocket server");
        }

        // Send handshake
        $request = "GET / HTTP/1.1\r\n" .
                   "Host: localhost\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";

        \fwrite($client, $request);
        \usleep(200000);
        $response = \fread($client, 2048);

        $this->assertIsString($response);
        $this->assertStringContainsString('101 Switching Protocols', $response);

        // Now publish message via RedisBroadcaster
        $broadcaster = new RedisBroadcaster($redisClient, $channel);
        $broadcaster->emit('redis_event', ['hello' => 'redis']);

        // Give it time to propagate from Broadcaster -> Redis -> Command Subscriber -> WebSocket connection
        \usleep(300000);

        $websocketFrame = \fread($client, 2048);

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);
        @\fclose($client);
        $redis->close();

        $this->assertIsString($websocketFrame);
        $this->assertNotEmpty($websocketFrame);
        $this->assertStringContainsString('redis_event', $websocketFrame);
        $this->assertStringContainsString('redis', $websocketFrame);
    }

    #[Test]
    public function it_recovers_when_redis_subscriber_child_process_dies(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        $host = \getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (\getenv('REDIS_PORT') ?: 6379);

        try {
            $redis = new \Redis();
            if (!@$redis->connect($host, $port, 0.5)) {
                $this->markTestSkipped('Redis server not available at ' . $host . ':' . $port);
            }
        } catch (\RedisException $e) {
            $this->markTestSkipped('Redis connection failed: ' . $e->getMessage());
        }

        $serverPort = 9088;

        $registry = new ConnectionRegistry();
        $driver = new StreamSocketDriver();
        $driver->setRegistry($registry);

        $channel = 'ml_serve_test_recover_' . \uniqid();

        $config = new Config([
            'sockets' => [
                'driver' => 'stream',
                'broadcast' => 'redis',
                'host' => '127.0.0.1',
                'port' => $serverPort,
                'redis' => [
                    'channel' => $channel,
                ],
            ],
        ]);

        $redisClient = new \MonkeysLegion\Sockets\Registry\PhpRedisClient($redis);

        $command = new SocketServerCommand(
            $driver,
            $config,
            $registry,
            $redisClient
        );

        global $argv;
        $oldArgv = $argv;
        $argv = ['bin/sockets', 'socket:serve', 'start', "--host=127.0.0.1", "--port=$serverPort"];

        $pid = \pcntl_fork();
        if ($pid === 0) {
            try {
                $command->__invoke();
            } catch (\Throwable) {
            }
            exit(0);
        }

        // Restore global argv
        $argv = $oldArgv;

        \usleep(500000);

        $client = \stream_socket_client("tcp://127.0.0.1:$serverPort");
        if (!$client) {
            \posix_kill($pid, SIGKILL);
            \pcntl_wait($status);
            $this->fail("Could not connect to WebSocket server");
        }

        // Send handshake
        $request = "GET / HTTP/1.1\r\n" .
                   "Host: localhost\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";

        \fwrite($client, $request);
        \usleep(200000);
        $response = \fread($client, 2048);

        $this->assertIsString($response);
        $this->assertStringContainsString('101 Switching Protocols', $response);

        // Find the child process (the subscriber) of the server process
        $output = [];
        \exec("pgrep -P " . $pid, $output);
        $childPid = isset($output[0]) ? (int) $output[0] : 0;
        
        $this->assertGreaterThan(0, $childPid, "Subscriber child process was not found");

        // Kill the subscriber child process
        \posix_kill($childPid, SIGKILL);

        // Wait for the server to detect closed pipe, sleep 1 sec, and respawn subscriber child process
        \usleep(1500000);

        // Now publish message via RedisBroadcaster
        $broadcaster = new RedisBroadcaster($redisClient, $channel);
        $broadcaster->emit('redis_recover_event', ['hello' => 'redis_recover']);

        // Give it time to propagate from Broadcaster -> Redis -> Command Subscriber -> WebSocket connection
        \usleep(300000);

        // Set stream to non-blocking to read what is available
        \stream_set_blocking($client, false);
        $websocketFrame = \fread($client, 2048);

        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);
        @\fclose($client);
        $redis->close();

        $this->assertIsString($websocketFrame);
        $this->assertNotEmpty($websocketFrame);
        $this->assertStringContainsString('redis_recover_event', $websocketFrame);
        $this->assertStringContainsString('redis_recover', $websocketFrame);
    }
}
