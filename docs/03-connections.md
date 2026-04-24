# Connection Registry

The Registry layer manages **distributed connection state** — it tracks who is online, what groups (tags) they belong to, and provides efficient lookup and iteration. This is the backbone of the room/channel system.

---

## Components

| Class | Purpose |
|:---|:---|
| `ConnectionInterface` | Contract for all connection objects |
| `ConnectionRegistryInterface` | Contract for all registry implementations |
| `ConnectionRegistry` | In-memory registry with `WeakMap` optimization |
| `RedisConnectionRegistry` | Redis-backed registry for multi-node clusters |
| `PhpRedisClient` | Adapter wrapping `ext-redis` for the `RedisClientInterface` |

---

## ConnectionInterface

**Namespace:** `MonkeysLegion\Sockets\Contracts`

The contract that every connection object must implement, regardless of the transport driver.

```php
interface ConnectionInterface
{
    public function getId(): string;
    public function send(string|MessageInterface $message): void;
    public function ping(string $payload = ''): void;
    public function close(int $code = 1000, string $reason = ''): void;
    public function lastActivity(): int;
    public function touch(): void;
    public function getMetadata(): array;
}
```

| Method | Description |
|:---|:---|
| `getId()` | Returns the unique identifier (typically `ip:port`) |
| `send()` | Sends a text/binary message or `MessageInterface` to the client |
| `ping()` | Sends a WebSocket Ping frame for keep-alive |
| `close()` | Initiates a graceful WebSocket close with a status code |
| `lastActivity()` | Returns the Unix timestamp of the last meaningful activity |
| `touch()` | Updates the activity timestamp to the current time |
| `getMetadata()` | Returns metadata set during the handshake (headers, auth info, etc.) |

### Close Codes (RFC 6455 §7.4)

| Code | Meaning |
|:---|:---|
| `1000` | Normal closure |
| `1001` | Server going away |
| `1002` | Protocol error |
| `1003` | Unsupported data |
| `1006` | Abnormal closure (idle timeout) |
| `1007` | Invalid frame payload |
| `1008` | Policy violation |
| `1009` | Message too big |

---

## ConnectionRegistryInterface

**Namespace:** `MonkeysLegion\Sockets\Contracts`

The contract that every registry must implement.

```php
interface ConnectionRegistryInterface
{
    public function add(ConnectionInterface $connection): void;
    public function remove(string|ConnectionInterface $connection): void;
    public function get(string $id): ?ConnectionInterface;
    public function tag(string|ConnectionInterface $connection, string $tag): void;
    public function untag(string|ConnectionInterface $connection, string $tag): void;
    public function getByTag(string $tag): iterable;
    public function all(): iterable;
}
```

| Method | Description |
|:---|:---|
| `add()` | Registers a new connection |
| `remove()` | Unregisters a connection and cleans up all associated tags |
| `get()` | Finds a connection by its unique ID |
| `tag()` | Assigns a tag (room/channel) to a connection |
| `untag()` | Removes a tag from a connection |
| `getByTag()` | Returns all connections that have a specific tag |
| `all()` | Returns all active connections |

---

## ConnectionRegistry (In-Memory)

**Namespace:** `MonkeysLegion\Sockets\Registry`  
**Implements:** `ConnectionRegistryInterface`, `Countable`, `IteratorAggregate`

The default, single-process registry. Uses a `WeakMap` for reverse-lookup tag management to ensure automatic garbage collection.

### Architecture

```text
$connections: array<string, ConnectionInterface>
     ┃
     ▼
$tags: array<string, array<string, bool>>     ← Forward: tag → connection IDs
     ┃
     ▼
$connectionTags: WeakMap<ConnectionInterface, array<string, bool>>  ← Reverse: connection → tags
```

### Why WeakMap?

When a connection is removed, the `WeakMap` is checked to find all tags associated with that connection. This avoids a costly full scan of the `$tags` array. Additionally, if the `ConnectionInterface` object is garbage-collected outside of a clean `remove()` call, the `WeakMap` entry is automatically purged.

### Property Hook: `$count`

Uses PHP 8.4's property hook:

```php
public int $count {
    get => \count($this->connections);
}
```

### Usage Example

```php
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;

$registry = new ConnectionRegistry();

// Register a new connection
$registry->add($connection);

// Tag (join a room)
$registry->tag($connection, 'room:lobby');
$registry->tag($connection, 'user:42');

// Find all connections in a room
foreach ($registry->getByTag('room:lobby') as $member) {
    $member->send('New member joined!');
}

// Untag (leave)
$registry->untag($connection, 'room:lobby');

// Remove (disconnect) — all tags are cleaned up automatically
$registry->remove($connection);

// Count connections
echo $registry->count; // PHP 8.4 property hook
echo count($registry); // Countable interface

// Iterate
foreach ($registry as $id => $connection) {
    // ...
}
```

---

## RedisConnectionRegistry

**Namespace:** `MonkeysLegion\Sockets\Registry`  
**Implements:** `ConnectionRegistryInterface`

A Redis-backed registry for **multi-node deployments**. Connection metadata and tag mappings are stored in Redis, allowing multiple WebSocket workers across different servers to share state.

### When to Use

| Scenario | Registry |
|:---|:---|
| Single server, single worker | `ConnectionRegistry` (in-memory) |
| Single server, multiple workers | `RedisConnectionRegistry` |
| Multi-server cluster | `RedisConnectionRegistry` |

### Data Structure in Redis

```text
ml:connections:{id}       → Hash (connection metadata)
ml:tags:{tag}             → Set (connection IDs)
ml:conn_tags:{id}         → Set (tags for a connection)
```

---

## PhpRedisClient

**Namespace:** `MonkeysLegion\Sockets\Registry`  
**Implements:** `RedisClientInterface`

A thin adapter wrapping the native PHP `ext-redis` extension to satisfy the `RedisClientInterface` contract.

```php
use MonkeysLegion\Sockets\Registry\PhpRedisClient;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$client = new PhpRedisClient($redis);
```

This decouples the library from any specific Redis client, allowing you to swap in Predis or any other implementation.
