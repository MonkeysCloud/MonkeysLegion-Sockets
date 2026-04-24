# WebSocketServer

The `WebSocketServer` is the **central orchestrator** of the MonkeysLegion Sockets architecture. It ties together the Registry, Broadcaster, Formatter, and Channel authorization into a single, ergonomic API. This is the class your application interacts with for all room/channel operations and broadcasting.

---

## Class Overview

**Namespace:** `MonkeysLegion\Sockets\Server`  
**Type:** `class`

```php
class WebSocketServer
{
    public function __construct(
        private readonly ConnectionRegistryInterface $registry,
        private readonly BroadcasterInterface $broadcaster,
        private readonly FormatterInterface $formatter,
        private readonly ?ChannelAuthorizerInterface $authorizer = null
    )
```

| Parameter | Type | Description |
|:---|:---|:---|
| `$registry` | `ConnectionRegistryInterface` | Manages connection state and tagging |
| `$broadcaster` | `BroadcasterInterface` | Distributes messages to workers |
| `$formatter` | `FormatterInterface` | Serializes event payloads |
| `$authorizer` | `?ChannelAuthorizerInterface` | Optional authorization for private/presence channels |

### Internal Wiring

On construction, the server automatically creates an internal `RoomManager` using the injected dependencies:

```php
$this->roomManager = new RoomManager(
    $this->registry,
    $this->broadcaster,
    $this->authorizer
);
```

---

## Complete Method Reference

### Channel Operations

#### `joinPublic(ConnectionInterface|string $connection, string $name): self`

Joins a connection to a public channel. Accepts either a `ConnectionInterface` object or a connection ID string.

```php
$server->joinPublic($connection, 'lobby');
// Registry tag created: public:lobby
```

#### `joinPrivate(ConnectionInterface|string $connection, string $name, array $parameters = []): bool`

Joins a connection to a private channel after authorization. Returns `true` on success, `false` if denied.

```php
$allowed = $server->joinPrivate($connection, 'team-5', ['token' => 'abc123']);
if (!$allowed) {
    $connection->send(json_encode(['error' => 'Unauthorized']));
}
```

#### `joinPresence(ConnectionInterface|string $connection, string $name, array $parameters = []): array|false`

Joins a connection to a presence channel. Returns the list of current members on success, `false` on authorization failure. Automatically emits `presence:joined` to existing members.

```php
$members = $server->joinPresence($connection, 'chat');
if ($members !== false) {
    $connection->send(json_encode([
        'event' => 'presence:members',
        'data' => $members
    ]));
}
```

#### `join(ConnectionInterface|string $connection, string $room): self`

**Legacy/Generic** room join. Tags the connection with `room:{name}`.

```php
$server->join($connection, 'general');
// Registry tag: room:general
```

#### `leavePublic(ConnectionInterface|string $connection, string $name): self`

Removes a connection from a public channel.

```php
$server->leavePublic($connection, 'lobby');
```

#### `leavePrivate(ConnectionInterface|string $connection, string $name): self`

Removes a connection from a private channel.

```php
$server->leavePrivate($connection, 'team-5');
```

#### `leave(ConnectionInterface|string $connection, string $room): self`

**Legacy/Generic** room leave. Untags `room:{name}`.

```php
$server->leave($connection, 'general');
```

---

### Broadcasting Operations

#### `to(string $room): BroadcasterInterface`

Targets a specific room for the next broadcast. Returns the broadcaster for fluent chaining.

```php
$server->to('general')->emit('message', ['text' => 'Hello everyone!']);
// Targets tag: room:general
```

#### `toConnection(string $id): BroadcasterInterface`

Targets a specific connection directly.

```php
$server->toConnection('192.168.1.5:54321')->emit('alert', 'Private message');
```

#### `broadcast(string $event, mixed $data = []): void`

Global broadcast to **all** connected clients.

```php
$server->broadcast('system', 'Server maintenance in 5 minutes');
```

---

### Accessor Methods

#### `getRegistry(): ConnectionRegistryInterface`

Returns the active connection registry.

```php
$count = count($server->getRegistry());
echo "Active connections: {$count}";
```

#### `getFormatter(): FormatterInterface`

Returns the configured payload formatter.

```php
$wire = $server->getFormatter()->format('ping', ['ts' => time()]);
```

---

## Connection ID Flexibility

All channel methods accept **either** a `ConnectionInterface` object or a **string connection ID**:

```php
// Using the connection object directly
$server->joinPublic($connection, 'lobby');

// Using a connection ID (resolved from the registry)
$server->joinPublic('192.168.1.5:54321', 'lobby');
```

When a string ID is provided, the server resolves it via `$this->registry->get($id)`. If the connection is not found, the operation is silently skipped.

---

## Full Lifecycle Example

```php
use MonkeysLegion\Sockets\Server\WebSocketServer;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;
use MonkeysLegion\Sockets\Broadcast\RedisBroadcaster;
use MonkeysLegion\Sockets\Protocol\JsonFormatter;
use MonkeysLegion\Sockets\Service\AuthorizerPipeline;

// 1. Build the stack
$registry    = new ConnectionRegistry();
$broadcaster = new RedisBroadcaster($redisClient);
$formatter   = new JsonFormatter();

$authorizer  = new AuthorizerPipeline();
$authorizer->addAuthorizer(new IpBlockerAuthorizer(), 100);
$authorizer->addAuthorizer(new TeamAuthorizer($db), 10);

$server = new WebSocketServer($registry, $broadcaster, $formatter, $authorizer);

// 2. Wire event handlers onto the driver
$driver->on('connect', function ($connection) use ($registry) {
    $registry->add($connection);
    echo "New connection: {$connection->getId()}\n";
});

$driver->on('message', function ($connection, $message) use ($server) {
    $parsed = json_decode($message->getPayload(), true);
    
    match ($parsed['action'] ?? null) {
        // Channel operations
        'join_public'   => $server->joinPublic($connection, $parsed['channel']),
        'join_private'  => $server->joinPrivate($connection, $parsed['channel'], $parsed['params'] ?? []),
        'join_presence' => $server->joinPresence($connection, $parsed['channel'], $parsed['params'] ?? []),
        'leave'         => $server->leavePublic($connection, $parsed['channel']),
        
        // Chat message
        'send_message'  => $server->to($parsed['channel'])->emit('message', [
            'from' => $connection->getId(),
            'text' => $parsed['text'],
        ]),
        
        // Direct message  
        'dm' => $server->toConnection($parsed['to'])->emit('dm', [
            'from' => $connection->getId(),
            'text' => $parsed['text'],
        ]),
        
        default => null,
    };
});

$driver->on('close', function ($connection) use ($registry) {
    $registry->remove($connection);
});

// 3. Start the server
$driver->listen('0.0.0.0', 8080);
```

---

## Architectural Position

```text
┌─────────────────────────────────────────────────────────┐
│                    Your Application                     │
│    (Controllers, Jobs, Commands, Event Listeners)       │
├─────────────────────────────────────────────────────────┤
│                                                         │
│                  WebSocketServer                        │
│    ┌──────────┬──────────┬──────────┬──────────┐       │
│    │ Registry │Broadcaster│Formatter │Authorizer│       │
│    └────┬─────┴─────┬────┴────┬─────┴────┬─────┘       │
│         │           │         │          │              │
│    ┌────▼────┐ ┌────▼────┐ ┌──▼──┐ ┌────▼─────┐       │
│    │ Memory  │ │  Redis  │ │JSON │ │ Pipeline │       │
│    │  or     │ │  or     │ │ or  │ │  or      │       │
│    │ Redis   │ │  Unix   │ │Pack │ │ Custom   │       │
│    └─────────┘ └─────────┘ └─────┘ └──────────┘       │
│                                                         │
├─────────────────────────────────────────────────────────┤
│                  Transport Driver                       │
│           (Stream / React / Swoole)                     │
└─────────────────────────────────────────────────────────┘
```

The `WebSocketServer` sits between your application logic and the transport layer. Your app uses the server's API for high-level operations (channels, broadcasting), while the driver handles the raw TCP/WebSocket mechanics.
