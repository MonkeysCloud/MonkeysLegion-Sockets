# Handshake Layer

The Handshake layer is the **first line of defense** in the MonkeysLegion Sockets architecture. Every incoming TCP connection must successfully complete the WebSocket handshake (RFC 6455 §4) before it is promoted to a full WebSocket connection. This layer validates, authenticates, and filters connections at the HTTP level — before any WebSocket frames are ever processed.

---

## Components

| Class | Purpose |
|:---|:---|
| `HandshakeNegotiator` | Core RFC 6455 handshake logic |
| `MiddlewarePipeline` | Onion-layer middleware executor |
| `AllowedOriginsMiddleware` | CSWH protection via Origin header |
| `IpFilterMiddleware` | IP blocklist / allowlist enforcement |
| `RateLimitMiddleware` | Connection flood protection |
| `JwtAuthenticator` | JWT-based authentication |
| `QueryTokenAuthenticator` | Token-in-query-string authentication |
| `RequestParser` | Raw HTTP → PSR-7 ServerRequest conversion |
| `ResponseFactory` | PSR-7 Response generation |
| `HandshakeException` | Typed exception for handshake failures |

---

## HandshakeNegotiator

**Namespace:** `MonkeysLegion\Sockets\Handshake`  
**Type:** `final readonly class`

The core negotiation engine. It validates the incoming PSR-7 `ServerRequestInterface` against RFC 6455 requirements and generates the `101 Switching Protocols` response.

### Constructor

```php
public function __construct(
    private ResponseFactoryInterface $responseFactory,
    private ?AuthenticatorInterface $authenticator = null,
    private ?MiddlewarePipeline $pipeline = null,
)
```

| Parameter | Type | Description |
|:---|:---|:---|
| `$responseFactory` | `ResponseFactoryInterface` | PSR-17 factory for building HTTP responses |
| `$authenticator` | `?AuthenticatorInterface` | Optional authentication strategy |
| `$pipeline` | `?MiddlewarePipeline` | Optional middleware chain for pre-negotiation filtering |

### Method: `negotiate(ServerRequestInterface $request): ResponseInterface`

This is the only public method. It runs the middleware pipeline (if any), then performs the core negotiation:

1. **Validates** that the request is a valid WebSocket upgrade (GET, `Upgrade: websocket`, `Connection: Upgrade`, `Sec-WebSocket-Key`, Version 13).
2. **Authenticates** connection if an `AuthenticatorInterface` is injected.
3. **Generates** the `Sec-WebSocket-Accept` key using the RFC 6455 GUID.
4. **Returns** a `101 Switching Protocols` response.

### Validation Rules

The following conditions cause a `HandshakeException`:

| Condition | Message |
|:---|:---|
| Method is not `GET` | `Handshake must be a GET request` |
| Missing `Upgrade: websocket` | `Missing "Upgrade: websocket" header` |
| Missing `Connection: Upgrade` | `Missing "Connection: Upgrade" header` |
| Missing `Sec-WebSocket-Key` | `Missing "Sec-WebSocket-Key" header` |
| Version is not `13` | `Only WebSocket version 13 is supported` |

### Usage Example

```php
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use MonkeysLegion\Sockets\Handshake\MiddlewarePipeline;
use MonkeysLegion\Sockets\Handshake\AllowedOriginsMiddleware;

// 1. Create a pipeline with security middleware
$pipeline = new MiddlewarePipeline();
$pipeline->add(new AllowedOriginsMiddleware(
    ['https://app.example.com', '*.staging.example.com'],
    new ResponseFactory()
));

// 2. Build the negotiator
$negotiator = new HandshakeNegotiator(
    responseFactory: new ResponseFactory(),
    pipeline: $pipeline
);

// 3. Negotiate (called internally by the Driver)
$response = $negotiator->negotiate($psrRequest);
// Returns 101 Switching Protocols on success
// Throws HandshakeException on failure
```

---

## MiddlewarePipeline

**Namespace:** `MonkeysLegion\Sockets\Handshake`

Orchestrates a chain of `HandshakeMiddlewareInterface` implementations using the **Onion Model** — each middleware wraps the next, with the core negotiation at the center.

### Constructor

```php
public function __construct(
    private array $middlewares = []
)
```

### Method: `add(HandshakeMiddlewareInterface $middleware): self`

Appends a middleware to the end of the pipeline. Returns `$this` for fluent chaining.

### Method: `process(ServerRequestInterface $request, callable $core): ResponseInterface`

Executes the pipeline by wrapping each middleware around the `$core` callable using `array_reduce`. Middlewares execute in the order they were added.

### How the Onion Works

```text
Request → [AllowedOrigins] → [RateLimit] → [IpFilter] → Core Negotiation → Response
                  ↑                                              |
                  └──────────────── Response flows back ─────────┘
```

Each middleware receives the request and a `$next` callable. It can:
- **Pass through:** Call `$next($request)` to continue.
- **Reject:** Return an error `ResponseInterface` directly (e.g. 403, 429).
- **Modify:** Alter the request before passing it forward.

### Usage Example

```php
$pipeline = new MiddlewarePipeline();

$pipeline
    ->add(new AllowedOriginsMiddleware(['https://app.example.com'], $responseFactory))
    ->add(new RateLimitMiddleware($responseFactory, maxAttempts: 5, window: 30))
    ->add(new IpFilterMiddleware($responseFactory, blockedIps: ['10.0.0.99']));
```

---

## AllowedOriginsMiddleware

**Namespace:** `MonkeysLegion\Sockets\Handshake`  
**Implements:** `HandshakeMiddlewareInterface`

Protects against **Cross-Site WebSocket Hijacking (CSWH)** by verifying the `Origin` header against a whitelist.

### Constructor

```php
public function __construct(
    private array $allowedOrigins,         // e.g. ['https://app.example.com', '*.internal.com']
    private ResponseFactoryInterface $responseFactory
)
```

### Matching Rules

| Pattern | Matches |
|:---|:---|
| `https://example.com` | Exact match only |
| `*.example.com` | Any subdomain (e.g. `app.example.com`, `staging.example.com`) |
| `*` | All origins (disables CSWH protection) |

### Behavior

- If the `Origin` header is **empty** (non-browser clients), the request is **allowed** to pass.
- If the origin doesn't match any entry, a **403 Forbidden** response is returned.

---

## IpFilterMiddleware

**Namespace:** `MonkeysLegion\Sockets\Handshake`  
**Implements:** `HandshakeMiddlewareInterface`

Provides both **blocklist** and **allowlist** IP filtering.

### Constructor

```php
public function __construct(
    private readonly ResponseFactoryInterface $responseFactory,
    private readonly array $blockedIps = [],  // Explicit deny
    private readonly array $allowedIps = []   // If set, only these pass
)
```

### Logic

1. If the IP is in `$blockedIps` → **403 IP Blocked**.
2. If `$allowedIps` is non-empty and the IP is NOT in it → **403 IP Not Whitelisted**.
3. Otherwise → pass through.

### Usage Example

```php
// Block known attackers, allow everything else
$middleware = new IpFilterMiddleware($responseFactory, blockedIps: ['192.168.1.100']);

// Strict allowlist mode for internal services
$middleware = new IpFilterMiddleware($responseFactory, allowedIps: ['10.0.0.1', '10.0.0.2']);
```

---

## RateLimitMiddleware

**Namespace:** `MonkeysLegion\Sockets\Handshake`  
**Implements:** `HandshakeMiddlewareInterface`

Protects against **connection flood attacks** using an in-memory sliding window counter.

### Constructor

```php
public function __construct(
    private readonly ResponseFactoryInterface $responseFactory,
    private readonly int $maxAttempts = 10,  // Max connections per window
    private readonly int $window = 60        // Window duration in seconds
)
```

### Behavior

- Tracks connection attempts per IP using an in-memory array.
- Automatically cleans up expired entries each cycle.
- Returns **429 Too Many Connection Attempts** with a `Retry-After` header when the limit is exceeded.

> **Note:** This uses in-memory storage. For multi-worker or clustered deployments, replace with a Redis-backed implementation.

### Usage Example

```php
// Allow 5 connections per 30-second window from the same IP
$middleware = new RateLimitMiddleware($responseFactory, maxAttempts: 5, window: 30);
```

---

## Authenticators

### JwtAuthenticator

**Implements:** `AuthenticatorInterface`

Validates JWT tokens from the `Authorization` header or query parameters during the handshake phase.

### QueryTokenAuthenticator

**Implements:** `AuthenticatorInterface`

Extracts a token from the WebSocket URL query string (e.g. `ws://host/ws?token=xxx`) and validates it. Useful for browser clients that cannot set custom headers during the WebSocket handshake.

### AuthenticatorInterface Contract

```php
interface AuthenticatorInterface
{
    public function authenticate(ServerRequestInterface $request): bool;
}
```

The authenticator receives the full PSR-7 request and returns `true` to allow the connection or `false` to reject it. When injected into `HandshakeNegotiator`, a `false` result throws a `HandshakeException`.

---

## RequestParser

**Namespace:** `MonkeysLegion\Sockets\Handshake`

Converts a raw HTTP handshake string (received from the TCP socket) into a PSR-7 `ServerRequestInterface`. This is used internally by the transport drivers.

```php
$parser = new RequestParser();
$psrRequest = $parser->parse($rawHttpString, $remoteAddress);
```

---

## ResponseFactory

**Namespace:** `MonkeysLegion\Sockets\Handshake`  
**Implements:** `Psr\Http\Message\ResponseFactoryInterface`

A lightweight, zero-dependency PSR-17 `ResponseFactoryInterface` implementation. Used as the default response factory throughout the handshake layer.

```php
$factory = new ResponseFactory();
$response = $factory->createResponse(101, 'Switching Protocols');
```
