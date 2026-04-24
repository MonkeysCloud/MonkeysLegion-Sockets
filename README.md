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
