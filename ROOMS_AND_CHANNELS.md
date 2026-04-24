# Rooms and Channels Architecture

🚀 **MonkeysLegion Sockets** uses a high-performance, tag-based system for managing groups of connections. This document explains the system of **Public and Private Rooms** and how to implement them.

## 🏗️ Core Concept: Tags
In this architecture, "Rooms" or "Channels" are not rigid objects. Instead, they are represented by **Tags** attached to a `ConnectionInterface`.

- **Multi-tenancy**: A single connection can have multiple tags (e.g., `User.42`, `room:lobby`, `role:admin`).
- **Distributed**: When using a Redis-backed `ConnectionRegistry`, tags are synchronized across the entire cluster.
- **Filtering**: The `Broadcaster` can target messages selectively using these tags.

---

## 🌍 Public Rooms
A **Public Room** is a group that any connected client can join or leave without specialized server-side authorization beyond the initial handshake.

### Implementation
Use the semantic `joinPublic` method. It automatically prefixes the tag with `public:` to match the broadcaster semantics.

```php
$server->on('room:join', function($connection, $data) use ($server) {
    $server->joinPublic($connection, $data['room']);
    
    // Notify room of new arrival
    $server->to("public:{$data['room']}")->emit('user_joined', [
        'id' => $connection->getId()
    ]);
});
```

---

## 🔒 Private Rooms
A **Private Room** requires the server to authorize the join request. 

### The System
The system relies on the **`ChannelAuthorizerInterface`** passed to the `WebSocketServer`.

#### 1. Authorization flow
When `joinPrivate($connection, $name, $params)` is called:
1. The server checks if an authorizer is registered.
2. The authorizer's `authorize()` method is called with the connection and parameters.
3. If successful, the connection is tagged with `private:$name`.

#### 2. Implementation
```php
// Define your rules
class MyAuthorizer implements ChannelAuthorizerInterface {
    public function authorize($connection, string $channel, array $params = []): bool {
        $userId = $connection->get('user_id');
        return $this->db->canAccess($userId, $channel);
    }
}

// In your server setup
$server = new WebSocketServer($registry, $broadcaster, $formatter, new MyAuthorizer());

// When client tries to join
$server->on('room:join_private', function($connection, $data) use ($server) {
    if ($server->joinPrivate($connection, $data['room'], $data['auth_params'] ?? [])) {
        $connection->send(json_encode(['event' => 'joined', 'room' => $data['room']]));
    } else {
        $connection->send(json_encode(['event' => 'error', 'message' => 'Unauthorized']));
    }
});
```

---

## 📡 Broadcasting to Rooms

You can target rooms using the `BroadcasterInterface`. While `to($tag)` is the base method, we provide semantic helpers for clarity.

### Semantic Channel Access
```php
// Broadcast to a public channel
$broadcaster->publicChannel('lobby')->emit('announcement', 'Welcome!');

// Target a private team channel
$broadcaster->privateChannel('team-alpha')->emit('covert_op', 'Go go go!');
```

### Pattern Binding
```php
// Target a specific user regardless of what server they are on
$broadcaster->channel('User.{id}', ['id' => 42])->emit('alert', 'Low balance!');
```

---

## 🛠️ Implementation Summary (v1.1.0)

All items from the original roadmap are now fully implemented and production-ready:

1. ✅ **`ChannelAuthorizerInterface`**: Formal contract for defining join-rules.
2. ✅ **`RoomManager`**: High-level service that handles authorization and presence event broadcasting.
3. ✅ **Presence Channels**: Semantic support via `joinPresence()` which notifies members and returns active occupant lists.

### Example Presence Workflow:
```php
// When a client joins a presence channel
$members = $server->joinPresence($connection, 'lobby', ['token' => '...']);

if ($members !== false) {
    // Current occupants are sent back to the joiner
    $connection->send(json_encode(['event' => 'presence:members', 'data' => $members]));
    
    // Everyone else already received 'presence:joined' via the RoomManager
}
```
