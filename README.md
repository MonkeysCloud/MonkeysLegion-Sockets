# MonkeysLegion Sockets

[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.4-777bb4.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

**MonkeysLegion Sockets** is a production-grade, transport-agnostic WebSocket foundation for PHP 8.4+. It provides a high-performance, protocol-perfect engine for building real-time applications without being tied to a specific async runtime.

---

## The Foundation (Protocol Integrity)

The core engine is built for absolute RFC 6455 compliance, ensuring your WebSocket server is a first-class citizen in the modern web.

* **Handshake Negotiator**: Precise challenge-response engine using PSR-7 for industry-standard protocol upgrades.
* **Frame Processor**: An optimized bitwise engine handling masking, fragmented frames (continuation), and payload lengths from 7-bit to 64-bit.
* **Request Parser**: A zero-dependency HTTP/1.1 parser designed to securely transition raw streams into legitimate PSR-7 requests.

### Usage: Protocol Level

```php
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use MonkeysLegion\Sockets\Handshake\RequestParser;

// Transition a raw binary stream into a secure WebSocket connection
$negotiator = new HandshakeNegotiator(new ResponseFactory());
$request = RequestParser::parse($rawHttpData);
$response = $negotiator->negotiate($request);
```

---

## Performance & Hardening

Phase 2 transforms the protocol engine into a scalable, adversarial-proof transport matrix designed to handle thousands of concurrent clients.

### 1. Multi-Driver Matrix

Switch transports by changing exactly one line of code. All drivers share the same contract and security guarantees:

* **Native Stream Driver**: High-performance, zero-dependency implementation using a non-blocking `stream_select` loop.
* **Swoole Driver**: Leverages C-level event loops for extreme performance and multi-threaded concurrency.
* **ReactPHP Driver**: Provides pure-PHP asynchronous transport for environments where PHP extensions are restricted.

### 2. High-Density Scaling

* **O(1) WeakMap Registry**: Ensures memory overhead remains constant regardless of connection count. Automatically prunes all metadata and room tags the millisecond a socket drops.
* **Distributed Redis Registry**: Enables cluster-wide scaling. Synchronize room memberships across multiple physical server nodes using high-speed Redis sets.

### 3. Adversarial Hardening (Security Audit)

* **Anti-OOM Protection**: Message reassembly is capped at a configurable 10MB to prevent fragmentation-based memory exhaustion attacks.
* **Slow-Loris Resilience**: 5MB non-blocking write buffers with automatic backpressure. The server terminates clients that stall the event loop.
* **UTF-8 Precision**: Text frames are validated at the bit-level to ensure strict RFC 6455 compliance (Close Code 1007).
* **Cryptographic entropy**: Uses `random_bytes()` for masking keys to prevent frame-injection attacks.

### Usage: Driver Level

You can instantiate drivers manually or use the built-in **DriverFactory** for configuration-driven setup.

#### 1. Configuration

```php
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | WebSocket Driver
    |--------------------------------------------------------------------------
    */
    'driver' => \getenv('WS_DRIVER') ?: 'stream',

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    */
    'host' => \getenv('WS_HOST') ?: '0.0.0.0',
    'port' => (int) (\getenv('WS_PORT') ?: 8080),

    /*
    |--------------------------------------------------------------------------
    | Driver Options
    |--------------------------------------------------------------------------
    */
    'options' => [
        'max_message_size' => 10 * 1024 * 1024, // 10MB
        'write_buffer_size' => 5 * 1024 * 1024, // 5MB
        'heartbeat_interval' => 30, // Seconds
    ],
];
```

#### 2. Using the Driver Factory

The `DriverFactory` automatically wires the `HandshakeNegotiator`, `FrameProcessor`, and `MessageAssembler` based on your configuration.

```php
use MonkeysLegion\Sockets\Service\DriverFactory;

$config = require __DIR__ . '/config/config.php';
$factory = new DriverFactory($config);

$driver = $factory->make(); // Returns the configured Driver instance
$driver->onMessage(fn($conn, $msg) => $conn->send("Perfect."));
$driver->listen($config['host'], $config['port']);
```

---

## 📜 Documentation & Standards

* **[Contributing Guidelines](CONTRIBUTING.md)**: How to help the Legion grow.
* **[Code Standards](code_standards.md)**: PSR-based quality rules.
* **[Code of Conduct](CODE_OF_CONDUCT.md)**: Community behavior expectations.
* **[Security Policy](SECURITY.md)**: Reporting critical vulnerabilities.

Built with ❤️ by the **MonkeysLegion Team**.
