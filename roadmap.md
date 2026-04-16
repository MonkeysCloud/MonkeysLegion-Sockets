# Roadmap: Native PHP WebSocket Integration

A high-performance, low-coupled, PSR-compliant WebSocket library for the modern PHP ecosystem.

## 🎯 Vision

To provide a "ready-to-go" WebSocket solution that feels native to PHP developers. The goal is to separate the **Transport Layer** (how bytes are moved) from the **Application Layer** (broadcasting logic), allowing for easy integration into existing Full-stack or Backend projects.

---

## 🏗️ Technical Foundation

- **PSR Compliance:** - `PSR-7` & `PSR-17` for HTTP Handshakes (interoperability with frameworks).
  - `PSR-11` for Container injection (Dependency Injection).
  - `PSR-3` for logging.
- **Low Coupling:** Core components communicate via Interfaces; no hard dependency on specific server extensions.
- **Concurrency Support:** Support for multiple event loops (Stream Select, Swoole, Libuv).

---

## 🗺️ Development Phases

### Phase 1: The Core Infrastructure (The Foundation) ✅

- [x] **Interface Definition:** Create `DriverInterface`, `ConnectionInterface`, and `MessageInterface`.
- [x] **Handshake Engine:** Implement a PSR-7 compliant negotiator to upgrade HTTP connections to WebSockets (RFC 6455).
- [x] **Native Driver:** Implement the `StreamSocketDriver` using native PHP `stream_socket_server`.
- [x] **Frame Handling:** Robust encoding/decoding of WebSocket frames (Masking, OpCodes, Fragmentation, and Binary payload support).
- [x] **SSL/TLS Support:** Native encryption support for Stream Sockets via SSL contexts.

### Phase 2: High-Performance Drivers & Scaling ✅

- [x] **Connection Management:** Efficiently store and retrieve active connections (Registry pattern).
- [x] **Heartbeat System:** Built-in Ping/Pong management to prune "ghost" clients.
- [x] **Authentication Middleware:** Secure handshake validation (JWT/Session).
- [ ] **Swoole Driver:** Optional driver for extreme high-concurrency environments.
- [ ] **ReactPHP/Amp Driver:** Integration for users already within an async ecosystem.
- [ ] **Write Buffering & Backpressure:** Non-blocking write queues to prevent slow clients from stalling the event loop.

### Phase 3: The Integration Layer (The "Bridge")

- [ ] **Broadcaster Component:** A mechanism to allow standard PHP-FPM requests to trigger broadcasts.

- [ ] **Redis Pub/Sub Adapter:** The primary bridge for horizontal scaling.
- [ ] **Internal IPC:** Unix Socket or Shared Memory bridge for single-server setups.
- [ ] **Authentication Handlers:** Defined `AuthenticatorInterface` for JWT and Session-based handshakes.
- [ ] **Middleware Support:** Allow custom logic (Rate Limiting, IP Filtering) during the handshake.

### Phase 4: Message Protocol & UX

- [ ] **Payload Formatters:** Default JSON formatter with support for custom implementations (Protobuf, MessagePack).

- [ ] **Channel/Room Logic:** Support for `join()`, `leave()`, and `to('room_name')->emit()`.
- [ ] **CLI Tooling:** A `bin/websocket` command to manage the server lifecycle (Start, Stop, Status).
- [ ] **Client-side JS:** A lightweight, zero-dependency JavaScript wrapper to handle auto-reconnects.

### Phase 5: The Frontend (JS Client)

- [ ] **Connection Lifecycle:** Implementation of `onOpen`, `onClose`, `onError`, and `onMessage` hooks.

- [ ] **Smart Reconnect:** Heartbeat-based health checks and exponential backoff reconnection logic.
- [ ] **Event Emitter Pattern:** A clean `.on('event', callback)` API to handle structured server messages.
- [ ] **Channel Subscription:** Logic to filter incoming messages based on "topics" or "channels."
- [ ] **Browser Compatibility:** ESM and UMD builds to support modern build tools (Vite/Webpack) and direct `<script>` tags.

---

## 🛠️ Proposed Architecture

```text
[ Web App / FPM ] -> [ Redis / Bridge ] -> [ WebSocket Server ]
                                                   |
                                           [ Driver Interface ]
                                           /        |         \
                             [Native Stream]    [Swoole]    [ReactPHP]

```

## 📋 PSR Implementation Details

- Log: All internal events (connection errors, frame drops) will use Psr\Log\LoggerInterface.

- DI: The server should be bootable via a Psr\Container\ContainerInterface to allow easy framework integration (Laravel, Symfony, MonkeysLegion).
