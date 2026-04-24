# Configuration & CLI

This document covers the **configuration schema**, **CLI commands**, and **service provider** that wire the entire MonkeysLegion Sockets stack into your application.

---

## Installation

```bash
composer require monkeyscloud/monkeyslegion-sockets

# Interactive installer
php ml socket:install
```

The installer will:
1. Ask for your preferred config format (`mlc` or `php`).
2. Publish the configuration file to your `config/` directory.
3. Optionally publish the JavaScript client to `public/js/vendor/monkeys-sockets.js`.

---

## Configuration Schema

### MLC Format (`config/sockets.mlc`)

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

### Configuration Reference

| Key | Type | Default | Description |
|:---|:---|:---|:---|
| `driver` | `string` | `stream` | Transport engine: `stream`, `react`, `swoole` |
| `registry` | `string` | `local` | Connection state: `local` (in-memory), `redis` |
| `broadcast` | `string` | `redis` | IPC strategy: `redis` (Pub/Sub), `unix` (socket) |
| `formatter` | `string` | `json` | Wire format: `json`, `msgpack` |
| `host` | `string` | `0.0.0.0` | Bind address |
| `port` | `int` | `8080` | Bind port |
| `unix.path` | `string` | `/tmp/ml_sockets.sock` | Unix socket file path |
| `options.max_message_size` | `int` | `10485760` | Max message size (bytes) |
| `options.write_buffer_size` | `int` | `5242880` | Max write buffer (bytes) |
| `options.heartbeat_interval` | `int` | `60` | Ping interval (seconds) |
| `security.allowed_origins` | `array` | `[]` | CSWH whitelist |

### Environment Variable Overrides

Every configuration value supports `${ENV_VAR:-default}` syntax. This means you can override any setting via environment variables without modifying the config file:

```bash
WS_DRIVER=swoole WS_PORT=9000 php ml socket:serve start
```

---

## CLI Commands

### `socket:install`

**Signature:** `php ml socket:install`

Interactive installer that publishes configuration files and JS client assets.

**Flow:**

```text
1. Asks: "Which configuration format? [mlc/php]" → Default: mlc
2. Publishes config/sockets.{format}
3. Asks: "Publish JS assets to public/js? (Y/n)" → Default: Y
4. Publishes public/js/vendor/monkeys-sockets.js
```

### `socket:serve`

**Signature:** `php ml socket:serve {action=start} [--host=] [--port=]`

Starts the WebSocket server.

**Arguments:**

| Argument | Default | Description |
|:---|:---|:---|
| `action` | `start` | Server action (currently only `start`) |

**Options:**

| Option | Default | Description |
|:---|:---|:---|
| `--host` | From config | Override bind address |
| `--port` | From config | Override bind port |

**Example:**

```bash
# Start with defaults
php ml socket:serve start

# Custom host and port
php ml socket:serve start --host=0.0.0.0 --port=9000
```

**Output:**

```text
🚀 Starting MonkeysLegion WebSocket Server...
📡 Driver: MonkeysLegion\Sockets\Driver\StreamSocketDriver
🔗 Bind:   0.0.0.0:8080
🛠️ Mode:   Production
--------------------------------------------------
```

**Signal Handling:**

The server registers `SIGINT` and `SIGTERM` handlers (via `pcntl`) for graceful shutdown:
- Sends Close frame (1001 Going Away) to all clients.
- Closes the listening socket.
- Exits cleanly with code 0.

```bash
# Graceful shutdown
kill -SIGTERM <pid>
# or Ctrl+C in the terminal
```

---

## SocketServiceProvider

**Namespace:** `MonkeysLegion\Sockets\Providers`  
**Attribute:** `#[Provider]`

Automatically discovered and registered by the MonkeysLegion framework. Binds all socket services into the DI container.

### Registration Order

The provider registers services in dependency order:

```text
1. RedisClientInterface      ← Wraps ext-redis
2. ConnectionRegistryInterface ← Local or Redis-backed
3. MiddlewarePipeline         ← Security middleware chain
4. HandshakeNegotiator        ← With pipeline and auth
5. DriverFactory              ← With registry, negotiator, redis
6. DriverInterface            ← Concrete driver from factory
7. BroadcasterInterface       ← Redis or Unix broadcaster
8. FormatterInterface         ← JSON or MsgPack
9. WebSocketServer            ← The master orchestrator
```

### Resolving Services

In your application code, you can resolve any service from the container:

```php
// In a Controller
public function __construct(
    private readonly BroadcasterInterface $broadcaster
) {}

public function updateOrder(int $orderId): Response
{
    // ... update logic ...
    
    $this->broadcaster->privateChannel("order-{$orderId}")->emit('status_changed', [
        'status' => 'shipped'
    ]);
    
    return new Response(200);
}
```

```php
// In a CLI Command or Job
public function __construct(
    private readonly WebSocketServer $server
) {}

public function handle(): void
{
    $this->server->broadcast('maintenance', [
        'message' => 'Server restarting in 5 minutes',
        'countdown' => 300
    ]);
}
```

---

## Deployment Checklist

| Step | Command / Action |
|:---|:---|
| Install package | `composer require monkeyscloud/monkeyslegion-sockets` |
| Publish config | `php ml socket:install` |
| Configure origins | Edit `security.allowed_origins` in config |
| Set Redis (if cluster) | Ensure `Redis::class` is in the DI container |
| Start server | `php ml socket:serve start` |
| Process manager | Use `supervisord` or `systemd` for production |
| Reverse proxy | Configure Nginx/Caddy for WebSocket proxying |

### Nginx WebSocket Proxy

```nginx
location /ws {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 86400;
}
```

### Supervisor Configuration

```ini
[program:monkeys-sockets]
command=php ml socket:serve start
directory=/var/www/app
autostart=true
autorestart=true
startsecs=5
stderr_logfile=/var/log/monkeys-sockets.err.log
stdout_logfile=/var/log/monkeys-sockets.out.log
```
