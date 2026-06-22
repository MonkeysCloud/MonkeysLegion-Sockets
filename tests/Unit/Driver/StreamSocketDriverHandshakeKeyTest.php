<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Driver;

use MonkeysLegion\Sockets\Driver\StreamConnection;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Frame\MessageAssembler;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * StreamSocketDriverHandshakeKeyTest
 *
 * Regression test for:
 *   Warning: Undefined array key <N> in StreamSocketDriver.php (handleData / processHeartbeats)
 *
 * Root cause:
 *   $this->handshaked[$streamId] is read directly without isset() / null-coalescing.
 *   Under FD-reuse or partial-cleanup scenarios the key is absent while
 *   $this->connections[$streamId] still holds a valid entry.
 *
 * Strategy:
 *   Use stream_socket_pair() to obtain a real resource handle, then inject
 *   an intentionally inconsistent state via Reflection (connection present,
 *   handshaked entry missing) and invoke the private handleData() method.
 *   A custom error handler captures any E_WARNING produced.
 */
final class StreamSocketDriverHandshakeKeyTest extends TestCase
{
    /** @var resource[] */
    private array $pair = [];

    protected function setUp(): void
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair, 'stream_socket_pair() must succeed');
        $this->pair = $pair;
    }

    protected function tearDown(): void
    {
        foreach ($this->pair as $s) {
            if (\is_resource($s)) {
                @\fclose($s);
            }
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function makeDriver(): StreamSocketDriver
    {
        return new StreamSocketDriver(
            frameProcessor:    new FrameProcessor(),
            assembler:         new MessageAssembler(),
            negotiator:        new HandshakeNegotiator(new ResponseFactory()),
        );
    }

    /**
     * Inject state into the driver so that $connections[$id] exists but
     * $handshaked[$id] is absent — the exact precondition that triggers
     * "Undefined array key".
     *
     * @param resource $stream The socket resource that will be used as streamId key.
     */
    private function injectInconsistentState(StreamSocketDriver $driver, mixed $stream): void
    {
        $streamId = (int) $stream;

        $connection = new StreamConnection(
            resource: $stream,
            id:       (string) $streamId,
            frameProcessor: new FrameProcessor(),
        );

        // Set connections[streamId] = connection
        $rConnections = new ReflectionProperty($driver, 'connections');
        $rConnections->setValue($driver, [$streamId => $connection]);

        // Set buffers[streamId] = '' (needed so line 288 ??= doesn't mask anything)
        $rBuffers = new ReflectionProperty($driver, 'buffers');
        $rBuffers->setValue($driver, [$streamId => '']);

        // Deliberately DO NOT set handshaked[$streamId]
        // streams must also be set so closeConnection can run without warnings
        $rStreams = new ReflectionProperty($driver, 'streams');
        $rStreams->setValue($driver, [$streamId => $stream]);
    }

    /**
     * Invoke the private handleData() method via Reflection.
     *
     * @param resource $stream
     */
    private function callHandleData(StreamSocketDriver $driver, mixed $stream): void
    {
        $method = new ReflectionMethod($driver, 'handleData');
        $method->invoke($driver, $stream);
    }

    /**
     * Invoke the private processHeartbeats() method via Reflection,
     * after setting lastHeartbeatCycle to 0 so it doesn't short-circuit.
     */
    private function callProcessHeartbeats(StreamSocketDriver $driver): void
    {
        // Force lastHeartbeatCycle to 0 so the heartbeat cycle runs
        $rCycle = new ReflectionProperty($driver, 'lastHeartbeatCycle');
        $rCycle->setValue($driver, 0);

        $method = new ReflectionMethod($driver, 'processHeartbeats');
        $method->invoke($driver);
    }

    /**
     * Wrap a callable in a custom E_WARNING error handler and return any
     * warnings that were produced as an array of message strings.
     *
     * @return string[]
     */
    private function captureWarnings(callable $fn): array
    {
        $warnings = [];

        \set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING) {
                $warnings[] = $errstr;
            }
            return true; // suppress from PHP output
        });

        try {
            $fn();
        } finally {
            \restore_error_handler();
        }

        return $warnings;
    }

    // ─── tests ──────────────────────────────────────────────────────────────

    /**
     * REGRESSION (handleData): Previously, calling handleData() when
     * $handshaked[$streamId] was absent (due to FD reuse or partial-cleanup)
     * produced: Warning: Undefined array key <N> in StreamSocketDriver.php
     *
     * The fix wraps every read as ($this->handshaked[$streamId] ?? false).
     *
     * @group regression
     */
    #[Test]
    public function it_does_not_produce_undefined_array_key_warning_when_handshaked_entry_is_missing(): void
    {
        [$serverSide, $clientSide] = $this->pair;

        // Write enough data so fread returns non-empty, bypassing the early return
        \fwrite($clientSide, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $driver = $this->makeDriver();
        $this->injectInconsistentState($driver, $serverSide);

        $warnings = $this->captureWarnings(
            fn () => $this->callHandleData($driver, $serverSide)
        );

        $undefinedKeyWarnings = \array_filter(
            $warnings,
            static fn (string $msg) => \str_contains($msg, 'Undefined array key')
        );

        $this->assertEmpty(
            $undefinedKeyWarnings,
            'handleData() must not raise "Undefined array key" E_WARNING when $handshaked entry is absent.'
        );
    }

    /**
     * FIXED: After patching handleData() to use ($this->handshaked[$streamId] ?? false),
     * no "Undefined array key" warning should be produced.
     *
     * @group regression
     */
    #[Test]
    public function it_does_not_produce_undefined_array_key_warning_after_fix(): void
    {
        [$serverSide, $clientSide] = $this->pair;

        \fwrite($clientSide, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $driver = $this->makeDriver();
        $this->injectInconsistentState($driver, $serverSide);

        $warnings = $this->captureWarnings(
            fn () => $this->callHandleData($driver, $serverSide)
        );

        $undefinedKeyWarnings = \array_filter(
            $warnings,
            static fn (string $msg) => \str_contains($msg, 'Undefined array key')
        );

        $this->assertEmpty(
            $undefinedKeyWarnings,
            'No "Undefined array key" E_WARNING should be raised after the fix. Got: ' .
            \implode(', ', $undefinedKeyWarnings)
        );
    }

    /**
     * REGRESSION (processHeartbeats): same undefined key warning can occur
     * in processHeartbeats() when $handshaked[$id] is accessed without isset().
     *
     * @group regression
     */
    #[Test]
    public function it_does_not_produce_undefined_array_key_warning_in_processHeartbeats_after_fix(): void
    {
        [$serverSide, $clientSide] = $this->pair;
        \fclose($clientSide);
        // Remove from pair so tearDown doesn't double-close
        $this->pair = [$serverSide];

        $driver = $this->makeDriver();
        $this->injectInconsistentState($driver, $serverSide);

        $warnings = $this->captureWarnings(
            fn () => $this->callProcessHeartbeats($driver)
        );

        $undefinedKeyWarnings = \array_filter(
            $warnings,
            static fn (string $msg) => \str_contains($msg, 'Undefined array key')
        );

        $this->assertEmpty(
            $undefinedKeyWarnings,
            'processHeartbeats() must not raise "Undefined array key" after the fix. Got: ' .
            \implode(', ', $undefinedKeyWarnings)
        );
    }
}
