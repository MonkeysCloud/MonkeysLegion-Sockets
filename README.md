# 🐒 MonkeysLegion Sockets

High-performance, secure, and cluster-ready WebSocket architecture for the MonkeysLegion framework. Designed for massive scalability, distributed state, and adversarial resilience.

## 🚀 Quick Setup

Install the package and publish the configuration:

```bash
composer require monkeyscloud/monkeyslegion-sockets

# Interactive installer for config and JS client assets
php ml socket:install
```

### Starting the Cluster
```bash
# Start the production server
php ml socket:serve start

# Or specify host/port
php ml socket:serve start --host=0.0.0.0 --port=9000
```

## 🛠️ Usage Examples

### Server-Side Broadcasting
Target dynamic user streams or rooms with ease:

```php
// Broadcast to a specific user on all connected devices
$broadcaster->channel('User.{id}', ['id' => 42])->emit('message', [
    'text' => 'Hello Monkey!'
]);

// Broadcast to a room
$broadcaster->to('room:lobby')->emit('message', 'Hello Monkeys!');
```

### Event Handling
Define your socket events in your service providers or controllers:

```php
$server->on('message', function($connection, $message) {
    echo "Received: " . $message->getPayload();
});
```

## 📚 Documentation

For exhaustive, professional documentation covering every layer of the architecture, please see the `docs/` folder:

- [Handshake Security](docs/01-handshake.md)
- [Frame Processing](docs/02-frames.md)
- [Connection Registry](docs/03-connections.md)
- [Broadcasting Layer](docs/04-broadcasting.md)
- [Transport Drivers](docs/05-drivers.md)
- [Channels & Authorization](docs/06-channels.md)
- [Protocol & Serialization](docs/07-protocol.md)
- [Services](docs/08-services.md)
- [WebSocket Server](docs/09-server.md)
- [Configuration & CLI](docs/10-configuration.md)
- [Real-World Project Scenario](docs/11-real-world-example.md)

## 🏗️ Architectural Overview

MonkeysLegion Sockets is a "Pure Consumer" DI-oriented library that decouples the transport layer (TCP/WebSockets) from the application logic. 

### Core Components
- **Broadcaster**: Handles signal distribution from the application to the WebSocket workers (via Redis Pub/Sub or Unix Socket IPC).
- **Registry**: Manages distributed connection state (Tags, Rooms, Occupants). Supports Redis-backed clusters for multi-node deployments.
- **Drivers**: Transport implementations (Native Stream, ReactPHP, Swoole).
- **Middleware**: Handshake-level filtering (Authentication, Origin Check, Rate Limiting).

## 📊 Choosing the Right Driver

Selecting the right engine depends on your concurrency needs:

| Driver | Best For | Pros | Cons |
| :--- | :--- | :--- | :--- |
| **Native** | Dev / Small App | Zero dependencies, Pure PHP | Blocking loop (High CPU) |
| **React** | High Concurrency | Asynchronous, Pure PHP | Higher Memory |
| **Swoole** | 50k+ Connections | C-Extension Performance, Lowest Footprint | Requires Swoole Ext |

## 🏠 Public and Private Channels
MonkeysLegion Sockets provides a first-class, semantically clear system for managing communication groups.

### 🌍 Public Channels
Public channels are open to any connected client. Use them for global notifications, lobby chats, or public feeds.
```php
// Server: Join a client to a public channel
$server->joinPublic($connection, 'lobby');

// Broadcast to a public channel
$broadcaster->publicChannel('lobby')->emit('announcement', 'Welcome!');
```

### 🔒 Private Channels
Private channels require server-side authorization. Use them for sensitive data, one-to-one messaging, or restricted groups.
```php
// Server: Join with authorization logic (Requires ChannelAuthorizerInterface)
$server->joinPrivate($connection, 'team-alpha', ['token' => '...']);

// Broadcast to a private channel
$broadcaster->privateChannel('team-alpha')->emit('covert_op', 'Go!');
```

### 👥 Presence Channels
A specialized type of private channel that tracks "Who's Online".
```php
// Server: Join and get current occupants
$members = $server->joinPresence($connection, 'status-room');

// 1. Automatically emits 'presence:joined' to existing members
// 2. Returns an array of current members to the joiner
```

For more details on implementing security rules, see the [Rooms and Channels Architecture](ROOMS_AND_CHANNELS.md) guide.

## 🔒 Security Hardening

- **Strict Liveness (Heartbeats)**: Proactive Ping/Pong cycles ensure the server reaps zombie clients and bypasses protocol-level tricks.
- **Backpressure Protection**: Configurable `write_buffer_size` prevents memory exhaustion attacks.
- **Protocol Integrity**: Rigid RFC 6455 enforcement; only valid WebSocket frames reset liveness timers.
- **CSWH Protection**: Built-in `AllowedOriginsMiddleware`.

## 🏗️ Full Configuration Reference
The `config/sockets.mlc` file controls every aspect of your cluster. Below is the complete schema with default values:

```mlc
sockets {
    # Transport driver: "stream", "swoole", or "react"
    driver ${WS_DRIVER:-stream},

    # State tracking: "local" or "redis"
    registry ${WS_REGISTRY:-local},

    # Pub/Sub strategy: "unix" (single-server) or "redis" (cluster)
    broadcast ${WS_BROADCAST:-redis},

    # Protocol Formatter: "json" or "msgpack"
    formatter ${WS_FORMATTER:-json},

    # Listening address and port
    host ${WS_HOST:-0.0.0.0},
    port ${WS_PORT:-8080},

    # Unix Socket path (required if broadcast is "unix")
    unix {
        path ${WS_UNIX_PATH:-/tmp/ml_sockets.sock},
    }

    # Low-level transport options
    options {
        # Maximum allowed WebSocket message size (Default: 10MB)
        max_message_size ${WS_MAX_MESSAGE_SIZE:-10485760},
        
        # Max outbound buffer per connection (Default: 5MB)
        write_buffer_size ${WS_WRITE_BUFFER_SIZE:-5242880},
        
        # Interval for active WebSocket Pings (Seconds)
        heartbeat_interval ${WS_HEARTBEAT_INTERVAL:-60},
    }

    # Handshake security
    security {
        # List of allowed domains for WebSocket handshakes (CSWH Protection)
        allowed_origins [
            "http://localhost:3000",
            "https://app.yoursite.com"
        ],
    }
}
```

---

## 📡 Communication Life-Cycles

To master the MonkeysLegion ecosystem, it is essential to understand the "Hub and Spoke" model. The architecture distinguishes between **Long-Lived** listeners and **Short-Lived** emitters to ensure maximum performance.

### 1. Server-to-Server (The Internal Bridge)
This flow connects your application logic (e.g., a Controller, Command, or Job) to the WebSocket cluster.

* **The Listener (Always Live):** When you run `php ml socket:serve`, the server starts a permanent loop. It keeps an "Eternal Ear" open to the **Redis Pub/Sub** or **Unix Socket** channel.
* **The Broadcaster (Flash Messenger):** When your app calls `$broadcaster->emit()`, it "lives" for a few milliseconds—just long enough to throw the message into the channel—and then "dies" immediately.
* **Benefit:** Your web request is never slowed down by the WebSocket delivery process. It's a "fire and forget" system.



### 2. Client-to-Server (The Upstream Flow)
Standard communication from the JS client to your backend logic.

* **The Flow:** The `MonkeysSocket` JS client initiates a handshake and remains "Live" in the browser. When it emits an event, the server's **Middleware** validates the request and triggers your defined event handlers.
* **Liveness:** Every message sent by the client resets the server-side "Heartbeat" timer, proving the connection is still active.

### 3. Client-to-Client (The Secure Relay)
Clients never talk directly to each other (P2P). Instead, the server acts as a secure **Mediator**.

* **The Process:** Client A emits to the server. The server verifies the permissions, looks up Client B in the **Registry**, and "relays" the message.
* **Echo Logic:** By default, the server broadcasts to "Everyone Else" (excluding the sender). This prevents "double-message" glitches in the sender's UI while ensuring the rest of the room is updated instantly.

---

### 📊 Summary Table

| Flow Type | Listener (Always Live) | Emitter (Lives & Dies) | Purpose |
| :--- | :--- | :--- | :--- |
| **Server-to-Server** | Socket Server (Worker) | App Broadcaster | Pushing logic updates to users |
| **Client-to-Server** | Socket Server (Worker) | JS Client | Sending user actions to backend |
| **Client-to-Client** | Other Connected Clients | The Sending Client | Private messaging / Chat rooms |

## 🏗️ Real-World Implementation Guide
 
Understanding which component to use and where to place your logic is key to a robust implementation.
 
### 🌲 The Architectural Tree
This map shows the hierarchy of responsibility within the system:
 
```text
WebSocketServer (The Orchestrator)
├── Handshake (Middleware Pipeline)
│   └── Rejects unauthorized HTTP requests before they become WebSockets.
├── Registry (State Manager)
│   └── Tracks who is online and what tags they have.
├── RoomManager (High-Level Logic)
│   ├── Public (Open rooms)
│   ├── Private (Authorized channels)
│   └── Presence (Real-time occupant tracking)
└── Broadcaster (Distribution Bridge)
    ├── Local (Internal delivery to local workers)
    └── Global (Distributed delivery via Redis/Unix)
```
 
### 🚀 Full Implementation Scenario: Secure Team Chat
 
#### 1. Define Authorization (The Policy)
Create a class that implements `ChannelAuthorizerInterface` to protect your channels.
 
```php
class ChatAuthorizer implements ChannelAuthorizerInterface 
{
    public function authorize(ConnectionInterface $connection, string $channel, array $params = []): bool 
    {
        $userId = $connection->getMetadata()['user_id'] ?? null;
        
        // Example: Only members of Team 5 can join 'team-5'
        if (str_starts_with($channel, 'team-')) {
            $teamId = (int) substr($channel, 5);
            return $this->db->isMember($userId, $teamId);
        }
        
        return true; // Allow other channels
    }
}
```

#### 💡 Advanced: Authorization Pipelines
For complex applications, you can decouple your rules into a **Pipeline**. This allows you to split logic (e.g. Rate Limiting vs Database checks) into separate, reusable classes.

```php
$pipeline = new AuthorizerPipeline();
$pipeline->addAuthorizer(new IpBlockerAuthorizer(), 100); // Check IP first (High priority)
$pipeline->addAuthorizer(new DatabaseAuthorizer(), 10);    // Check DB second

$server = new WebSocketServer($registry, $broadcaster, $formatter, $pipeline);
```
 
#### 2. Bootstrap the Server
In your Service Provider or entry point, wire up the components.
 
```php
$server = new WebSocketServer(
    $registry, 
    $broadcaster, 
    $formatter, 
    new ChatAuthorizer() // Inject your policy
);
 ù
// Attach event logic
$server->on('message', function($connection, $data) use ($server) {
    if ($data['event'] === 'join_team') {
        $server->joinPrivate($connection, "team-{$data['team_id']}");
    }
});
```
 
#### 3. Emitting from your Application
Anywhere in your app (Controllers, Jobs, Commands), use the **Broadcaster** to push updates.
 
```php
// In a Controller after a database update
$broadcaster->privateChannel('team-5')->emit('task_updated', [
    'task_id' => 123,
    'status' => 'completed'
]);
```

---

## 🏗️ Developer Notes & Standards

### MonkeysLegion v2 Standards
This project strictly adheres to the [MonkeysLegion PHP 8.4 Code Standards](CODE_STANDARDS.md), including:
- **Attribute-First Configuration**: Metadata driven by native PHP 8.4 attributes.
- **Type-Safe Everything**: Mandatory strict types and PHPStan Level 9 compliance.
- **Asymmetric Visibility**: Using modern property hooks and refined visibility.
- **PSR Compliance**: Adherence to PSR-1, 4, 7, 11, 12, and 15.

To contribute or work on the library, ensure you have the `pcntl` and `posix` extensions for integration testing.

---
*Built with ❤️ by the MonkeysLegion Team (Advanced Agentic Coding).*
