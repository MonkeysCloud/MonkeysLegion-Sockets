# Services

The Service layer provides **infrastructure utilities** that support the core WebSocket architecture. These are standalone services that can be used independently or wired together via the DI container.

---

## HeartbeatManager

**Namespace:** `MonkeysLegion\Sockets\Service`  
**Type:** `final readonly class`

Manages WebSocket keep-alive by sending Ping frames to idle connections and closing those that fail to respond within the timeout window.

### Constructor

```php
public function __construct(
    private ConnectionRegistryInterface $registry,
    private int $idleTimeout = 60,   // Close after this many seconds of inactivity
    private int $pingInterval = 30   // Send Ping after this many seconds of idle
)
```

| Parameter | Type | Default | Description |
|:---|:---|:---|:---|
| `$registry` | `ConnectionRegistryInterface` | — | The connection registry to scan |
| `$idleTimeout` | `int` | `60` | Seconds before a connection is closed |
| `$pingInterval` | `int` | `30` | Seconds before a proactive Ping is sent |

### Method: `check(): void`

Iterates over all connections in the registry and performs two checks:

1. **Idle > `$idleTimeout`:** Close the connection with code `1006` (Abnormal Closure) and remove it from the registry.
2. **Idle > `$pingInterval` but < `$idleTimeout`:** Send a WebSocket Ping frame (opcode `0x9`).

### Usage Example

```php
use MonkeysLegion\Sockets\Service\HeartbeatManager;
use MonkeysLegion\Sockets\Registry\ConnectionRegistry;

$registry = new ConnectionRegistry();
$heartbeat = new HeartbeatManager($registry, idleTimeout: 120, pingInterval: 60);

// Called periodically in the event loop
$heartbeat->check();
```

### Timing Diagram

```text
Connection Activity Timeline:
├────────────── 0s ── connected ──────────────────────────────────┤
│                                                                │
├── 30s idle ── Ping sent ───────────────────────────────────────┤
│               │                                                │
│         Pong received ── timer reset ──────────────────────────┤
│                          │                                     │
│                    30s idle ── Ping sent ──────────────────────┤
│                                │                               │
│                          No Pong received                      │
│                                │                               │
│                          60s idle ── CLOSED (1006) ────────────┤
```

### Integration with StreamSocketDriver

The `StreamSocketDriver` has its own built-in heartbeat system (`processHeartbeats()`) that follows the same logic. The standalone `HeartbeatManager` is provided for use with custom drivers or external event loops.

---

## DriverFactory

**Namespace:** `MonkeysLegion\Sockets\Service`

See [05-drivers.md](05-drivers.md#driverfactory) for complete documentation.

---

## RoomManager

**Namespace:** `MonkeysLegion\Sockets\Service`

See [06-channels.md](06-channels.md#roommanager) for complete documentation.

---

## AuthorizerPipeline

**Namespace:** `MonkeysLegion\Sockets\Service`

See [06-channels.md](06-channels.md#authorizerpipeline) for complete documentation.
