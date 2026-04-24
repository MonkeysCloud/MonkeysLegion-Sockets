# Transport Drivers

The Driver layer provides the **transport engine** — the actual TCP server that accepts connections, reads bytes, and manages the event loop. MonkeysLegion Sockets ships with three driver implementations, each optimized for different concurrency profiles. The `DriverFactory` abstracts driver selection behind a single configuration key.

---

## Components

| Class | Purpose |
|:---|:---|
| `DriverInterface` | Contract for all transport drivers |
| `StreamSocketDriver` | Native PHP stream-based driver (zero dependencies) |
| `ReactSocketDriver` | ReactPHP-based async event loop driver |
| `SwooleDriver` | Swoole C-extension coroutine driver |
| `StreamConnection` | Connection implementation for the Stream driver |
| `ReactConnection` | Connection implementation for the React driver |
| `SwooleConnection` | Connection implementation for the Swoole driver |
| `DriverFactory` | Configuration-driven driver instantiation |

---

## DriverInterface

**Namespace:** `MonkeysLegion\Sockets\Contracts`

```php
interface DriverInterface
{
    public function listen(string $address, int $port, array $context = []): void;
    public function stop(): void;
    public function on(string $event, callable $callback): void;
    public function setRegistry(ConnectionRegistryInterface $registry): void;
}
```

| Method | Description |
|:---|:---|
| `listen()` | Binds to the address/port and enters the event loop (blocking) |
| `stop()` | Gracefully shuts down the server and closes all connections |
| `on()` | Registers an event callback (e.g. `message`, `connect`, `close`) |
| `setRegistry()` | Injects the connection registry for state management |

---

## StreamSocketDriver

**Namespace:** `MonkeysLegion\Sockets\Driver`  
**Type:** `final class`

The default, zero-dependency driver using PHP's native `stream_socket_server` and `stream_select`. Ideal for development and small-to-medium deployments.

### Constructor

```php
public function __construct(
    private readonly FrameProcessor $frameProcessor = new FrameProcessor(),
    private readonly MessageAssembler $assembler = new MessageAssembler(),
    private readonly HandshakeNegotiator $negotiator = new HandshakeNegotiator(new ResponseFactory()),
    private readonly LoggerInterface $logger = new NullLogger(),
    private readonly int $writeBufferSize = 5242880,   // 5MB
    private readonly int $heartbeatInterval = 60        // seconds
)
```

### Event Loop Architecture

```text
┌──────────────────────────────────────────────┐
│                  loop()                       │
│                                              │
│  ┌─── stream_select() ─────────────────┐    │
│  │  Wait for readable/writable streams │    │
│  └──────────────────────────────────────┘    │
│           │                                  │
│     ┌─────┴─────┐                            │
│     │           │                            │
│  Server?    Client?                          │
│     │           │                            │
│  accept()   handleData()                     │
│     │           │                            │
│     │     ┌─────┴─────┐                      │
│     │  Handshake?   Frame?                   │
│     │     │            │                     │
│     │  negotiate()  decode() → assemble()    │
│     │                  │                     │
│     │              callback('message')       │
│     │                                        │
│  processHeartbeats() ← periodic             │
│                                              │
└──────────────────────────────────────────────┘
```

### Key Features

| Feature | Detail |
|:---|:---|
| **Non-blocking I/O** | Uses `stream_set_blocking(false)` for all client sockets |
| **Write buffering** | Outbound data is buffered and flushed via `stream_select` writability |
| **Heartbeat system** | Proactive ping/pong cycles with zombie reaping |
| **Backpressure** | Configurable `writeBufferSize` prevents memory exhaustion |
| **RFC 6455 compliance** | Only valid WebSocket frames reset liveness timers (prevents idle bypass attacks) |

### Heartbeat Logic

The heartbeat system runs periodically during the event loop:

1. If a connection has been idle for `>= heartbeatInterval` seconds → Send a Ping.
2. If a connection has been idle for `>= heartbeatInterval * 2` seconds → Reap (close with 1006).
3. **Security:** Only opcodes `0x1` (Text), `0x2` (Binary), `0x9` (Ping), and `0xA` (Pong) reset the activity timer. Continuation frames (`0x0`) are deliberately excluded to prevent "Infinite Idle Bypass" attacks.

---

## ReactSocketDriver

**Namespace:** `MonkeysLegion\Sockets\Driver`

An event-driven driver built on **ReactPHP**. Uses a non-blocking event loop for superior concurrency in pure PHP, without the need for C extensions.

### When to Use

- High-concurrency scenarios (thousands of connections)
- When you need async I/O without installing Swoole
- Integrating with other ReactPHP ecosystem components

### Constructor

```php
public function __construct(
    private readonly FrameProcessor $frameProcessor = new FrameProcessor(),
    private readonly HandshakeNegotiator $negotiator = new HandshakeNegotiator(new ResponseFactory()),
    private readonly MessageAssembler $messageAssembler = new MessageAssembler(),
    private readonly LoggerInterface $logger = new NullLogger(),
    private readonly int $writeBufferSize = 5242880,
    private readonly int $heartbeatInterval = 60,
    private readonly int $maxMessageSize = 10485760
)
```

---

## SwooleDriver

**Namespace:** `MonkeysLegion\Sockets\Driver`

A coroutine-based driver using the **Swoole C extension**. Delivers the highest performance and lowest memory footprint for 50k+ concurrent connections.

### Requirements

- `ext-swoole` must be installed
- Swoole 5.0+ recommended

### Constructor

```php
public function __construct(
    private readonly LoggerInterface $logger = new NullLogger(),
    private readonly int $writeBufferSize = 5242880,
    private readonly int $heartbeatInterval = 60,
    private readonly int $maxMessageSize = 10485760
)
```

---

## Driver Comparison

| Feature | Stream | React | Swoole |
|:---|:---|:---|:---|
| **Dependencies** | None (Pure PHP) | `react/socket` | `ext-swoole` |
| **Concurrency Model** | `stream_select` | Event loop | Coroutines |
| **Max Connections** | ~1,000 | ~10,000 | ~100,000+ |
| **Memory per conn** | ~50KB | ~30KB | ~10KB |
| **Best For** | Dev / Small apps | Production (no ext) | High-scale production |
| **CPU Pattern** | Blocking (higher) | Non-blocking | Non-blocking |

---

## DriverFactory

**Namespace:** `MonkeysLegion\Sockets\Service`

A configuration-driven factory that centralizes driver instantiation with all the necessary dependencies.

### Constructor

```php
public function __construct(
    private readonly array $config,
    private readonly LoggerInterface $logger = new NullLogger()
)
```

### Configuration Array

```php
$config = [
    'driver' => 'stream',    // 'stream', 'react', or 'swoole'
    'options' => [
        'max_message_size'    => 10485760,  // 10MB
        'write_buffer_size'   => 5242880,   // 5MB
        'heartbeat_interval'  => 60,        // seconds
    ],
];
```

### Methods

| Method | Description |
|:---|:---|
| `make(?string $driverName = null)` | Creates the configured driver with all deps |
| `setRegistry(ConnectionRegistryInterface)` | Injects registry for the driver |
| `setRedis(?Redis)` | Injects Redis for the broadcaster |
| `setNegotiator(HandshakeNegotiator)` | Injects a custom negotiator |
| `createBroadcaster()` | Creates a `RedisBroadcaster` using the injected Redis |

### Usage Example

```php
use MonkeysLegion\Sockets\Service\DriverFactory;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;

$factory = new DriverFactory([
    'driver' => 'stream',
    'options' => [
        'heartbeat_interval' => 30,
        'write_buffer_size'  => 2 * 1024 * 1024, // 2MB
    ],
], $logger);

$factory->setRegistry(new ConnectionRegistry());

$driver = $factory->make();
$driver->on('message', function ($connection, $message) {
    $connection->send('Echo: ' . $message->getPayload());
});

$driver->listen('0.0.0.0', 8080);
```
