# Broadcasting Layer

The Broadcasting layer is the **distribution bridge** between your application logic and the WebSocket workers. It decouples the "sender" (your Controller, Job, or Command) from the "receiver" (the long-lived WebSocket server process). This separation ensures your web requests are never blocked by WebSocket delivery.

---

## Components

| Class | Purpose |
|:---|:---|
| `BroadcasterInterface` | Contract for all broadcaster implementations |
| `RedisBroadcaster` | Distributed broadcaster via Redis Pub/Sub |
| `UnixBroadcaster` | Low-latency broadcaster via Unix Domain Socket |
| `RedisSubscriber` | Server-side Redis Pub/Sub listener |
| `UnixSubscriber` | Server-side Unix socket listener |
| `BroadcastBridge` | Connects incoming broadcast messages to the local registry |

---

## BroadcasterInterface

**Namespace:** `MonkeysLegion\Sockets\Contracts`

The contract that every broadcaster must implement. Provides both targeted and global emission capabilities with a fluent API.

```php
interface BroadcasterInterface
{
    public function broadcast(MessageInterface|string $message): void;
    public function to(string $tag): self;
    public function toConnection(string $connectionId): self;
    public function publicChannel(string $name): self;
    public function privateChannel(string $name): self;
    public function channel(string $pattern, array $parameters): self;
    public function emit(string $event, mixed $data = []): void;
    public function raw(MessageInterface|string $message): void;
}
```

### Fluent API

The broadcaster uses a fluent pattern for targeting:

```php
// Target a tag, then emit
$broadcaster->to('room:lobby')->emit('message', ['text' => 'Hello!']);

// Target a specific connection
$broadcaster->toConnection('192.168.1.5:54321')->emit('alert', 'Direct message');

// Public channel sugar
$broadcaster->publicChannel('lobby')->emit('announcement', 'Welcome!');

// Private channel sugar
$broadcaster->privateChannel('team-alpha')->emit('classified', $data);

// Dynamic pattern binding
$broadcaster->channel('User.{id}', ['id' => 42])->emit('notification', $data);

// Global broadcast (no target)
$broadcaster->emit('system', 'Maintenance in 5 minutes');
```

### State Management

After every `emit()` or `raw()` call, the target state is **automatically reset** to prevent accidental cross-broadcasting:

```php
$broadcaster->to('room:lobby')->emit('msg', 'Hello');
// Internal state is now reset — next call requires new targeting
$broadcaster->emit('global', 'This goes everywhere'); // ← Global broadcast
```

---

## RedisBroadcaster

**Namespace:** `MonkeysLegion\Sockets\Broadcast`  
**Implements:** `BroadcasterInterface`

The production-grade broadcaster for **multi-server deployments**. Publishes messages to a Redis Pub/Sub channel that the WebSocket workers subscribe to.

### Constructor

```php
public function __construct(
    private readonly RedisClientInterface $redis
)
```

### Message Envelope

Every message is serialized as a JSON envelope before being published to Redis:

```json
{
    "type": "tag",
    "target": "room:lobby",
    "event": "message",
    "data": { "text": "Hello Monkeys!" },
    "timestamp": 1714000000.123456
}
```

| Field | Description |
|:---|:---|
| `type` | Targeting strategy: `"tag"`, `"connection"`, or `"broadcast"` |
| `target` | The specific tag or connection ID (null for global) |
| `event` | The event name |
| `data` | The event payload (any serializable type) |
| `timestamp` | Microsecond precision timestamp |

### Channel Name

All messages are published to `ml_sockets:broadcast` by default.

### Usage Example

```php
use MonkeysLegion\Sockets\Broadcast\RedisBroadcaster;
use MonkeysLegion\Sockets\Registry\PhpRedisClient;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$broadcaster = new RedisBroadcaster(new PhpRedisClient($redis));

// From a Controller
$broadcaster->privateChannel('order-updates')->emit('order_shipped', [
    'order_id' => 1234,
    'tracking'  => 'UPS-XXX',
]);
```

---

## UnixBroadcaster

**Namespace:** `MonkeysLegion\Sockets\Broadcast`  
**Implements:** `BroadcasterInterface`

A high-performance, **zero-dependency** broadcaster for single-server setups. Communicates with the WebSocket worker via a Unix Domain Socket (`.sock` file) using a fire-and-forget pattern.

### Constructor

```php
public function __construct(
    private readonly string $socketPath  // e.g. '/tmp/ml_sockets.sock'
)
```

### How It Works

1. The WebSocket server creates a Unix socket at the configured path.
2. When your app calls `emit()`, the broadcaster opens a connection to the `.sock` file.
3. It writes the JSON envelope + newline and immediately closes.
4. The WebSocket server reads the message and dispatches it.

### Advantages over Redis

| Aspect | Unix | Redis |
|:---|:---|:---|
| Latency | ~0.01ms (kernel IPC) | ~0.5ms (network) |
| Dependencies | None | `ext-redis` + Redis server |
| Multi-server | ❌ | ✅ |
| Throughput | Very high | High |

### Usage Example

```php
use MonkeysLegion\Sockets\Broadcast\UnixBroadcaster;

$broadcaster = new UnixBroadcaster('/tmp/ml_sockets.sock');
$broadcaster->to('room:lobby')->emit('message', 'Hello!');
```

---

## RedisSubscriber

**Namespace:** `MonkeysLegion\Sockets\Broadcast`

The server-side listener that subscribes to the Redis Pub/Sub channel and dispatches incoming messages to the `BroadcastBridge`.

### Key Features

- **Always-live loop:** Runs inside the WebSocket server process.
- **Reconnection resilience:** Handles Redis disconnections gracefully.
- **State tracking:** Exposes `isRunning()` and `stop()` for clean shutdown.

---

## UnixSubscriber

**Namespace:** `MonkeysLegion\Sockets\Broadcast`

The server-side listener for Unix socket broadcasts.

### Key Features

- **Creates** the socket file with secure permissions on startup.
- **Non-blocking** accept loop for high throughput.
- **Automatic cleanup** of the socket file on shutdown.

---

## Dynamic Pattern Binding

The `channel()` method allows you to define dynamic broadcast targets using placeholder patterns:

```php
// Target a user across all their devices
$broadcaster->channel('User.{id}', ['id' => 42])->emit('notification', $data);

// Target a specific order's channel
$broadcaster->channel('Order.{orderId}.status', ['orderId' => 1234])->emit('update', $data);
```

If any parameter is missing, a `RuntimeException` is thrown:

```php
// This throws: "Some placeholders in [User.{id}] were not provided"
$broadcaster->channel('User.{id}', [])->emit('test', []);
```
