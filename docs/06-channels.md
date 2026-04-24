# Channels & Authorization

The Channel layer provides a **semantically clear, secure system** for managing communication groups. It builds on top of the Registry's tagging system to offer three distinct channel types: Public, Private, and Presence.

---

## Components

| Class | Purpose |
|:---|:---|
| `ChannelAuthorizerInterface` | Contract for authorization logic |
| `RoomManager` | High-level orchestrator for channel join/leave operations |
| `AuthorizerPipeline` | Composable pipeline for multiple authorization rules |

---

## Channel Types

### Public Channels

Open to any authenticated connection. No authorization check is performed.

**Tag format:** `public:{name}`

```php
$server->joinPublic($connection, 'lobby');
// Registry tag: public:lobby
```

### Private Channels

Require server-side authorization via `ChannelAuthorizerInterface`. The authorizer receives the connection, channel name, and optional parameters.

**Tag format:** `private:{name}`

```php
$result = $server->joinPrivate($connection, 'team-alpha', ['token' => '...']);
// Registry tag: private:team-alpha
// Returns true if authorized, false otherwise
```

### Presence Channels

A specialized private channel that **tracks occupants** in real-time. When a member joins or leaves, events are automatically broadcast to all other members.

**Tag format:** `private:presence:{name}`

```php
$members = $server->joinPresence($connection, 'chat-room');
// Registry tag: private:presence:chat-room
// Returns array of current members (excluding the joiner)
// Automatically emits 'presence:joined' to existing members
```

---

## ChannelAuthorizerInterface

**Namespace:** `MonkeysLegion\Sockets\Contracts`

```php
interface ChannelAuthorizerInterface
{
    public function authorize(
        ConnectionInterface $connection, 
        string $channel, 
        array $parameters = []
    ): bool;
}
```

| Parameter | Description |
|:---|:---|
| `$connection` | The connection attempting to join |
| `$channel` | The channel name (without the `private:` prefix) |
| `$parameters` | Optional data from the client (e.g. tokens, passwords) |

### Implementation Example

```php
use MonkeysLegion\Sockets\Contracts\ChannelAuthorizerInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;

class TeamAuthorizer implements ChannelAuthorizerInterface
{
    public function __construct(
        private readonly TeamRepository $teams
    ) {}

    public function authorize(ConnectionInterface $connection, string $channel, array $parameters = []): bool
    {
        $userId = $connection->getMetadata()['user_id'] ?? null;
        
        if ($userId === null) {
            return false;
        }
        
        // Channel format: "team-{id}"
        if (str_starts_with($channel, 'team-')) {
            $teamId = (int) substr($channel, 5);
            return $this->teams->isMember($userId, $teamId);
        }
        
        // Channel format: "admin"
        if ($channel === 'admin') {
            return $this->teams->isAdmin($userId);
        }
        
        return false;
    }
}
```

---

## RoomManager

**Namespace:** `MonkeysLegion\Sockets\Service`

The high-level service that orchestrates all channel operations. Handles authorization validation, tag management, and presence event broadcasting.

### Constructor

```php
public function __construct(
    private readonly ConnectionRegistryInterface $registry,
    private readonly BroadcasterInterface $broadcaster,
    private readonly ?ChannelAuthorizerInterface $authorizer = null,
    private readonly LoggerInterface $logger = new NullLogger()
)
```

### Methods

#### `joinPublic(ConnectionInterface $connection, string $room): void`

Tags the connection with `public:{room}`. No authorization required.

#### `joinPrivate(ConnectionInterface $connection, string $room, array $parameters = []): bool`

1. Checks if an authorizer is registered (returns `false` with a warning if not).
2. Calls `authorize()` on the authorizer.
3. If authorized, tags the connection with `private:{room}`.
4. Returns `true` on success, `false` on failure.

#### `joinPresence(ConnectionInterface $connection, string $room, array $parameters = []): array|false`

1. Calls `joinPrivate()` internally with channel `presence:{room}`.
2. Queries the registry for existing members in `private:presence:{room}`.
3. Broadcasts `presence:joined` to all existing members with the joiner's data.
4. Returns the list of current members (excluding the joiner) on success, `false` on auth failure.

**Presence Event Payloads:**

```json
// presence:joined (sent to existing members)
{
    "room": "chat-room",
    "member": {
        "id": "192.168.1.5:54321",
        "info": { "name": "Alice" }
    }
}

// presence:left (sent when a member leaves)
{
    "room": "chat-room",
    "member_id": "192.168.1.5:54321"
}
```

#### `leave(ConnectionInterface $connection, string $channel): void`

1. Removes the tag from the connection.
2. If the channel starts with `private:presence:`, broadcasts a `presence:left` event.

### Member Data

The `getMemberData()` method extracts identity information from the connection's metadata:

```php
private function getMemberData(ConnectionInterface $connection): array
{
    $metadata = $connection->getMetadata();
    return [
        'id' => $connection->getId(),
        'info' => $metadata['user_info'] ?? []
    ];
}
```

To populate `user_info`, set it during the handshake authentication phase.

---

## AuthorizerPipeline

**Namespace:** `MonkeysLegion\Sockets\Service`  
**Implements:** `ChannelAuthorizerInterface`

A composable authorizer that chains multiple authorization rules in a **prioritized sequence**. All authorizers in the pipeline must pass for the request to be approved.

### Constructor

```php
// No constructor arguments — use addAuthorizer() to build the pipeline
$pipeline = new AuthorizerPipeline();
```

### Method: `addAuthorizer(ChannelAuthorizerInterface $authorizer, int $priority = 0): self`

Adds an authorizer to the pipeline. **Higher priority values execute first.**

### Method: `authorize(ConnectionInterface $connection, string $channel, array $parameters = []): bool`

Iterates through all authorizers in priority order. Returns `false` immediately if any authorizer rejects. Returns `false` if the pipeline is empty (fail-closed).

### Priority Execution Order

```text
Priority 100: IpBlockerAuthorizer  ← Runs first (cheapest check)
Priority  50: RateLimitAuthorizer  ← Runs second
Priority  10: DatabaseAuthorizer   ← Runs last (most expensive)
```

### Usage Example

```php
use MonkeysLegion\Sockets\Service\AuthorizerPipeline;

$pipeline = new AuthorizerPipeline();

// High priority (100): Block known bad actors immediately
$pipeline->addAuthorizer(new IpBlockerAuthorizer(), 100);

// Medium priority (50): Check rate limits
$pipeline->addAuthorizer(new RateLimitAuthorizer(), 50);

// Low priority (10): Hit the database for membership checks
$pipeline->addAuthorizer(new TeamAuthorizer($teamRepo), 10);

// Inject into the server
$server = new WebSocketServer($registry, $broadcaster, $formatter, $pipeline);
```

### Design Philosophy

The pipeline follows the **fail-fast** principle:

1. Cheap checks (IP, rate limits) run first → reject early, save resources.
2. Expensive checks (database, external API) run last → only reached for legitimate requests.
3. Empty pipeline → denied by default (fail-closed security).

---

## Full Integration Example

```php
// 1. Define your authorization rules
class ChatPolicy implements ChannelAuthorizerInterface {
    public function authorize(ConnectionInterface $conn, string $channel, array $params = []): bool {
        $user = $conn->getMetadata()['user_id'] ?? null;
        return $user !== null; // Any authenticated user can join
    }
}

class AdminPolicy implements ChannelAuthorizerInterface {
    public function authorize(ConnectionInterface $conn, string $channel, array $params = []): bool {
        if (!str_starts_with($channel, 'admin')) return true; // Not our concern
        return ($conn->getMetadata()['role'] ?? '') === 'admin';
    }
}

// 2. Build a pipeline
$pipeline = new AuthorizerPipeline();
$pipeline->addAuthorizer(new ChatPolicy(), 10);
$pipeline->addAuthorizer(new AdminPolicy(), 20);

// 3. Wire it up
$server = new WebSocketServer($registry, $broadcaster, $formatter, $pipeline);

// 4. Handle client requests
$server->on('message', function($conn, $data) use ($server) {
    $parsed = json_decode($data->getPayload(), true);
    
    match ($parsed['action'] ?? '') {
        'join_public'  => $server->joinPublic($conn, $parsed['channel']),
        'join_private' => $server->joinPrivate($conn, $parsed['channel'], $parsed['params'] ?? []),
        'join_presence' => $server->joinPresence($conn, $parsed['channel']),
        'leave'        => $server->leavePublic($conn, $parsed['channel']),
        default        => null,
    };
});
```
