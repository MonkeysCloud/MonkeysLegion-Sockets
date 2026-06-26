# Package Map — Read This First

A single-page mental model of **MonkeysLegion Sockets**. Use this to see how pieces connect before diving into the layer-specific docs (`01-handshake.md` … `11-real-world-example.md`).

---

## What This Package Does

It is a **WebSocket server library** for MonkeysLegion apps with two jobs:

1. **Keep a long-lived worker alive** that accepts WebSocket clients, tracks who is connected, and runs your event handlers.
2. **Let the rest of your app push messages** (controllers, jobs, CLI) without talking to sockets directly — via a **Broadcaster** that hands off to Redis or a Unix socket.

Everything else is plumbing between those two roles.

---

## Entry Points (Where Life Starts)

| Entry | File | What happens |
|:---|:---|:---|
| **Framework install** | `composer.json` → `SocketServiceProvider` | MonkeysLegion auto-discovers the provider and wires the DI container from `config/sockets.mlc`. |
| **Start the worker** | `SocketServerCommand` (`php ml socket:serve start`) | Binds host/port, wires the broadcast listener, runs your bootstrap hook, then blocks in the driver event loop. |
| **Push from your app** | `BroadcasterInterface` (injected anywhere) | Short-lived: publishes one message to Redis/Unix and returns. The worker delivers it to clients. |
| **Your socket logic** | `SocketServerBootstrapInterface` (optional) | Implement `boot($driver, $webSocketServer)` to attach `$server->on('message', …)` before `listen()`. |

There is no `index.php` in this package — it is a **library**. The real runtime entry is **`SocketServerCommand`**.

---

## Boot Sequence (`socket:serve start`)

```text
php ml socket:serve start
        │
        ▼
SocketServerCommand::handle()
        │
        ├─ resolveBindAddress()          ← CLI flags override config/sockets.mlc
        ├─ installSignalHandlers()       ← SIGINT/SIGTERM → driver->stop() (not on Swoole)
        ├─ new BroadcastBridge(...)      ← receives app messages, sends to local clients
        ├─ wireSubscriber(bridge)      ← driver-specific Redis/Unix listener
        ├─ runBootstrapHook()          ← your SocketServerBootstrapInterface::boot()
        └─ driver->listen(host, port)  ← BLOCKS HERE (event loop)
```

**Key idea:** The command is thin. It does not parse WebSocket frames itself — it connects the **broadcast ear** to the **transport engine**.

---

## DI Wiring (`SocketServiceProvider`)

When the framework boots, services are registered in this order:

```text
SocketServiceProvider::register()
        │
        ├─ RedisClientInterface          ← wraps ext-redis (needed for redis registry/broadcast)
        ├─ ConnectionRegistryInterface   ← local in-memory OR Redis-backed cluster
        ├─ MiddlewarePipeline            ← AllowedOriginsMiddleware (+ your additions)
        ├─ HandshakeNegotiator           ← RFC 6455 upgrade + middleware + optional auth
        ├─ DriverFactory                 ← builds driver from config['driver']
        ├─ DriverInterface               ← stream | react | swoole
        ├─ BroadcasterInterface          ← RedisBroadcaster | UnixBroadcaster
        ├─ FormatterInterface            ← JsonFormatter | MsgPackFormatter
        └─ WebSocketServer               ← high-level API; driver attached via setDriver()
```

Config keys live in `config/sockets.mlc`: `driver`, `registry`, `broadcast`, `formatter`, `host`, `port`, `options`, `security`.

---

## The Two Traffic Lanes

### Lane A — Client → Server (upstream)

```text
Browser / JS client
        │  TCP + HTTP upgrade
        ▼
Driver (stream | react | swoole)
        │  raw bytes
        ▼
HandshakeNegotiator (+ MiddlewarePipeline)   ← reject bad origins, rate limits, JWT, etc.
        │  101 Switching Protocols
        ▼
FrameProcessor + MessageAssembler            ← RFC 6455 frames → complete message
        │  MessageInterface
        ▼
Your handler: $server->on('message', fn ...)
        │  optional
        ▼
WebSocketServer / RoomManager                ← joinPublic, joinPrivate, joinPresence
        │
        ▼
ConnectionRegistry                           ← add connection, tag with rooms/channels
```

### Lane B — App → Client (downstream / broadcast)

```text
Controller / Job / anywhere in PHP
        │
        ▼
BroadcasterInterface->publicChannel('lobby')->emit('event', $data)
        │  publishes JSON envelope to Redis channel or Unix socket
        ▼
Subscriber wiring (per driver)               ← StreamSubscriberWiring | React… | Swoole…
        │  listens inside the worker process
        ▼
BroadcastBridge::handle($payload)
        │  resolves target: all | tag | single connection
        ▼
ConnectionRegistry->getByTag() / get()
        │
        ▼
ConnectionInterface->send()                  ← driver encodes frame and writes to socket
```

**Why two lanes?** Your web request must not block on socket I/O. The broadcaster is fire-and-forget; the worker is always listening.

---

## Component Roles (Quick Reference)

### Orchestration

| Component | Role |
|:---|:---|
| `WebSocketServer` | Public API: rooms/channels, `on('message')`, delegates to `RoomManager` + `Driver`. |
| `SocketServerCommand` | CLI entry: banner, signals, subscriber wiring, bootstrap hook, `listen()`. |
| `SocketServiceProvider` | Wires the whole stack into MonkeysLegion DI. |

### Transport (drivers)

| Component | Role |
|:---|:---|
| `DriverInterface` | Contract: `listen`, `stop`, `onOpen/onMessage/onClose/onError`, `setRegistry`. |
| `DriverFactory` | Reads config, builds driver + shared `FrameProcessor`, `MessageAssembler`, `HandshakeNegotiator`. |
| `StreamSocketDriver` | Native PHP `stream_select` loop. |
| `ReactSocketDriver` | ReactPHP event loop. |
| `SwooleDriver` | Swoole `WebSocket\Server` (C extension). |
| `*Connection` classes | Per-driver wrapper implementing `ConnectionInterface` (send, metadata, buffer limits). |

### Protocol & frames

| Component | Role |
|:---|:---|
| `HandshakeNegotiator` | Validates upgrade headers, computes accept key, returns 101 response. |
| `MiddlewarePipeline` | Runs handshake middleware before upgrade (origins, IP, rate limit). |
| `FrameProcessor` | Encode/decode WebSocket frames (RFC 6455). |
| `MessageAssembler` | Reassembles fragmented frames; enforces `max_message_size`. |
| `JsonFormatter` / `MsgPackFormatter` | App-level payload encoding for events. |
| `JsonMessageSerializer` | Used by `BroadcastBridge` to wrap event + data for delivery. |

### State

| Component | Role |
|:---|:---|
| `ConnectionRegistry` | In-memory map: connection ID → connection, tags → connection sets. |
| `RedisConnectionRegistry` | Decorator: syncs tags/connections across cluster nodes. |
| `RoomManager` | Public / private / presence channel join logic + authorization. |
| `AuthorizerPipeline` | Chain multiple `ChannelAuthorizerInterface` implementations. |
| `HeartbeatManager` | (Used by drivers) ping idle clients, reap zombies. |

### Broadcasting

| Component | Role |
|:---|:---|
| `BroadcasterInterface` | App-side emitter: `emit()`, `to()`, `publicChannel()`, `privateChannel()`. |
| `RedisBroadcaster` | PUBLISH to Redis channel (cluster mode). |
| `UnixBroadcaster` | Write to Unix domain socket (single-server mode). |
| `BroadcastBridge` | Worker-side: decode envelope → find targets in registry → `send()`. |
| `*SubscriberWiring` | Hooks Redis/Unix listener into each driver's event loop model. |

### Security (handshake layer)

| Component | Role |
|:---|:---|
| `AllowedOriginsMiddleware` | CSWSH protection — reject unknown `Origin`. |
| `IpFilterMiddleware` | Allow/deny by IP. |
| `RateLimitMiddleware` | Throttle handshake attempts. |
| `JwtAuthenticator` / `QueryTokenAuthenticator` | Optional auth during upgrade. |

---

## Layer Diagram

```text
┌─────────────────────────────────────────────────────────────┐
│  YOUR APP                                                    │
│  BroadcasterInterface (emit)  │  SocketServerBootstrap (on) │
└──────────────┬──────────────────────────────┬───────────────┘
               │ Redis / Unix                  │ callbacks
               ▼                               ▼
┌──────────────────────────┐    ┌─────────────────────────────┐
│  BroadcastBridge         │    │  WebSocketServer            │
│  + SubscriberWiring      │    │  + RoomManager              │
└──────────────┬───────────┘    └──────────────┬──────────────┘
               │                               │
               └──────────────┬────────────────┘
                              ▼
               ┌──────────────────────────────┐
               │  ConnectionRegistry         │
               │  (local or Redis-backed)    │
               └──────────────┬───────────────┘
                              ▼
               ┌──────────────────────────────┐
               │  DriverInterface              │
               │  stream │ react │ swoole       │
               └──────────────┬───────────────┘
                              ▼
               ┌──────────────────────────────┐
               │  Handshake + Frames           │
               │  Negotiator, FrameProcessor   │
               └──────────────────────────────┘
```

---

## Driver Comparison — What Actually Differs

All three drivers implement the **same** `DriverInterface` and share `HandshakeNegotiator`, registry, heartbeat settings, and buffer limits from config. Your `on('message')` code does not change when you switch drivers.

What changes is **how bytes move** and **how the broadcast subscriber is wired into the loop**.

| | **Stream** (`stream`) | **React** (`react`) | **Swoole** (`swoole`) |
|:---|:---|:---|:---|
| **Engine** | `stream_socket_server` + `stream_select` | ReactPHP `SocketServer` + event loop | `Swoole\WebSocket\Server` |
| **Dependencies** | None (pure PHP) | `react/socket` | `ext-swoole` |
| **Concurrency** | One thread, multiplexed sockets | Async non-blocking I/O | Coroutines / native C server |
| **Frame handling** | Manual: `FrameProcessor` + `MessageAssembler` in PHP loop | Same manual stack on React connections | Swoole decodes frames natively; less PHP frame code |
| **Event loop** | Custom `while ($running)` + `stream_select` | `Loop::run()` | `$server->start()` |
| **Broadcast subscriber** | `StreamSubscriberWiring` — adds streams to `select`, Redis via fork+pipe | `ReactSubscriberWiring` — timers/streams on React loop | `SwooleSubscriberWiring` — separate process or coroutine |
| **Graceful shutdown** | `pcntl` SIGINT/SIGTERM → `stop()` | Same | Swoole handles its own lifecycle |
| **Sweet spot** | Local dev, small deployments, zero deps | Production without C extensions, ~thousands of connections | High scale (10k–100k+), lowest per-connection cost |
| **Trade-off** | Simple but CPU spins on `select`; ~1k connections practical | More memory per connection; pure PHP async | Requires Swoole install; different deployment model |

### Decision guide

```text
Just developing or < ~500 clients?     → stream
Need production concurrency, no Swoole?  → react
Have ext-swoole and serious scale?     → swoole
```

### Same everywhere (do not re-learn per driver)

- Handshake middleware pipeline
- Connection registry and tagging (`public:`, `private:`, `room:`)
- Heartbeat interval and write buffer size from `sockets.options`
- `BroadcastBridge` delivery semantics (broadcast / tag / direct)
- `WebSocketServer` channel APIs

---

## Config Knobs That Matter

| Key | Values | Effect |
|:---|:---|:---|
| `driver` | `stream`, `react`, `swoole` | Transport engine |
| `registry` | `local`, `redis` | Single node vs cluster connection state |
| `broadcast` | `unix`, `redis` | How app processes talk to the worker |
| `formatter` | `json`, `msgpack` | Wire format for events |
| `options.heartbeat_interval` | seconds | Idle ping / reap timing |
| `options.write_buffer_size` | bytes | Backpressure cap per connection |
| `options.max_message_size` | bytes | Max assembled message size |
| `security.allowed_origins` | URL list | Handshake origin check |

---

## File Tree (Where to Look)

```text
src/
├── Providers/SocketServiceProvider.php   ← DI entry
├── Cli/Command/SocketServerCommand.php   ← CLI entry
├── Server/WebSocketServer.php            ← orchestrator API
├── Service/
│   ├── DriverFactory.php
│   ├── RoomManager.php
│   └── HeartbeatManager.php
├── Driver/
│   ├── StreamSocketDriver.php
│   ├── ReactSocketDriver.php
│   └── SwooleDriver.php
├── Handshake/                            ← upgrade + middleware
├── Frame/                                ← RFC 6455 encode/decode
├── Registry/                             ← who is online
├── Broadcast/                            ← app → worker → clients
├── Protocol/                             ← JSON / MsgPack formatters
└── Contracts/                            ← interfaces everything implements
```

---

## 30-Second Recap

1. **`SocketServiceProvider`** builds the graph; config in **`sockets.mlc`** picks driver/registry/broadcast.
2. **`socket:serve`** starts the worker: broadcast listener + your bootstrap + **`driver->listen()`**.
3. **Clients** hit the **driver** → handshake → frames → your **`on('message')`** handlers.
4. **App code** uses **`BroadcasterInterface`** → Redis/Unix → **`BroadcastBridge`** → registry lookup → **`send()`**.
5. **`WebSocketServer`** is the friendly layer for rooms/channels; **`Driver`** is the engine; **`Registry`** is memory of who is where.

Read next: [Handshake](01-handshake.md) → [Frames](02-frames.md) → [Broadcasting](04-broadcasting.md) → [Drivers](05-drivers.md) for depth on each layer.
