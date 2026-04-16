# MonkeysLegion Sockets

🚀 **High-Performance, PSR-Compliant WebSocket Library for MonkeysLegion v2.**

MonkeysLegion Sockets is a modern PHP WebSocket library designed for reliability, performance, and flexibility. It follows the MonkeysLegion v2 standards, utilizing PHP 8.4+ features like property hooks and asymmetric visibility.

## ✨ Features

- **PSR-7 & PSR-15 Compliant**: Orchestrates seamlessly with existing PHP stacks.
- **Native Stream Support**: High-performance socket handling without external C extensions.
- **RFC 6455 Compliant**: Full support for the WebSocket protocol, including binary payloads and masking.
- **Zero-Trust Testing**: Comprehensive test suite with absolute zero tolerance for deprecations or notices.
- **Optimized Performance**: Global namespace function lookups for maximum execution speed.
- **Security Oriented**: O(1) connection registries and complexity-hardened cleanup logic.

## 🛠️ Setup

### Installation

```bash
composer require monkeyscloud/monkeyslegion-sockets
```

### Development Setup

1. **Clone the repository**:
   ```bash
   git clone https://github.com/MonkeysCloud/MonkeysLegion-Sockets.git
   ```

2. **Install dependencies**:
   ```bash
   composer install
   ```

3. **Run Tests**:
   ```bash
   composer test
   ```

4. **Static Analysis (PHPStan Level 9)**:
   ```bash
   composer analyze
   ```

## 🏗️ Phase 2 Progress: Connection & State ✅

Phase 2 has been completed, focusing on managing thousands of connections securely:

- **O(1) Connection Registry**: A high-performance store with bidirectional mapping for instant lookups and cleanup.
- **Tagging & Groups**: Built-in support for "Rooms" / "Topics" for targeted broadcasting.
- **Heartbeat Manager**: Automated Ping/Pong cycles to prune dead connections and maintain state.
- **Handshake Authentication**: Plug-and-play authentication middleware for JWT, Session, or API Key validation.

## 🏗️ Phase 1 Progress: The Foundation ✅

Phase 1 has been successfully established the core infrastructure of the library:

- **Core Contracts**: Defined transport-agnostic interfaces for Drivers, Connections, and Messages.
- **Handshake Negotiator**: A robust engine for validating RFC 6455 handshakes using PSR-7 Requests.
- **Frame Processor**: A bitwise engine for encoding and decoding binary WebSocket frames with full masking support.
- **StreamSocket Driver**: A native PHP implementation for high-concurrency socket handling.

## 🧭 Roadmap

See [roadmap.md](roadmap.md) for the full development schedule.

## 📜 Standards

This project adheres to the [MonkeysLegion V2 Code Standards](code_standards.md).

---
Built with ❤️ by the MonkeysLegion Team.
