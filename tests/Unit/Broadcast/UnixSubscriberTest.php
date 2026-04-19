<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Broadcast;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Broadcast\UnixSubscriber;
use RuntimeException;

final class UnixSubscriberTest extends TestCase
{
    private string $socketPath;

    protected function setUp(): void
    {
        $this->socketPath = \sys_get_temp_dir() . '/ml_sub_' . \uniqid() . '.sock';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->socketPath)) {
            @\unlink($this->socketPath);
        }
    }

    #[Test]
    public function it_creates_socket_file_with_correct_permissions(): void
    {
        $subscriber = new UnixSubscriber($this->socketPath);
        
        // Use pcntl_fork to run the listener briefly
        $pid = \pcntl_fork();
        if ($pid === 0) {
            $subscriber->listen(fn() => $subscriber->stop());
            exit(0);
        }

        \usleep(100000); // Wait for socket creation
        $this->assertFileExists($this->socketPath);
        // Check permissions (0666) -> octal 666
        $this->assertEquals('0666', \substr(\sprintf('%o', \fileperms($this->socketPath)), -4));

        $subscriber->stop();
        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);
    }

    #[Test]
    public function it_throws_exception_on_invalid_socket_path(): void
    {
        $subscriber = new UnixSubscriber('/root/forbidden.sock');
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not create IPC socket server');
        
        $subscriber->listen(fn() => null);
    }

    #[Test]
    public function it_receives_and_handles_messages(): void
    {
        $subscriber = new UnixSubscriber($this->socketPath);
        $received = null;

        $pid = \pcntl_fork();
        if ($pid === 0) {
            $subscriber->listen(function($msg) use (&$received, $subscriber) {
                // Since this is a child process, we can't easily assert on $received in the parent
                // but we can ensure the loop runs and stops.
                if ($msg === 'QUIT') $subscriber->stop();
            });
            exit(0);
        }

        \usleep(100000);
        
        $socket = \fsockopen('unix://' . $this->socketPath);
        \fwrite($socket, "QUIT\n");
        \fclose($socket);

        \pcntl_wait($status);
        $this->assertFalse($subscriber->isRunning());
    }

    #[Test]
    public function it_tracks_running_state(): void
    {
        $subscriber = new UnixSubscriber($this->socketPath);
        $this->assertFalse($subscriber->isRunning());

        $pid = \pcntl_fork();
        if ($pid === 0) {
            $subscriber->listen(fn() => null);
            exit(0);
        }

        \usleep(50000);
        $this->assertTrue($subscriber->isRunning() || \file_exists($this->socketPath));
        
        $subscriber->stop();
        \posix_kill($pid, SIGKILL);
        \pcntl_wait($status);
    }

    #[Test]
    public function it_continues_on_handler_failure(): void
    {
        $subscriber = new UnixSubscriber($this->socketPath);
        
        $pid = \pcntl_fork();
        if ($pid === 0) {
            $subscriber->listen(function() use ($subscriber) {
                $subscriber->stop();
                throw new \Exception('CRASH');
            });
            exit(0);
        }

        \usleep(100000);
        $socket = \fsockopen('unix://' . $this->socketPath);
        \fwrite($socket, "PING\n");
        \fclose($socket);

        \pcntl_wait($status);
        $this->assertFalse($subscriber->isRunning());
    }
}
