# 🐒 MonkeysLegion Sockets: Roadmap

Development path for the MonkeysLegion WebSocket ecosystem.

## ✅ Phase 1-4: Infrastructure & Hardening (Completed)
- **Multi-Driver Transport**: Native Stream, ReactPHP, and Swoole drivers implemented.
- **Security**: Heartbeat reaping (Strict Liveness), CSWH protection, and Backpressure limits.
- **Broadcasting**: Redis Pub/Sub and Unix Socket strategies with pattern binding (`User.{id}`).
- **DI Integration**: Fully configurable via `sockets.mlc`.

## 🚀 Phase 5: JavaScript Client (Upcoming)

A specialized, zero-dependency client designed for speed and reliability.

### 📍 Distribution Strategy
- **Location**: `client/monkeys-sockets.js` (Standalone vanilla file).
- **Format**: UMD (Universal Module Definition). Works via `<script>` tags (Global), ES Modules (`import`), and CommonJS (`require`).
- **NPM Package**: `monkeyscloud/monkeyslegion-sockets-js` (Future sync).
- **No-Build Ready**: Usable directly in any browser without Webpack, Vite, or Babel.

### 🛠️ Core Features
1. **Adaptive Reconnection**
   - Exponential backoff with random jitter.
   - Intelligent reconnect logic (starts slow, speeds up on partial success).
2. **State Preservation & Recovery**
   - Automatically re-joins rooms and re-binds channel listeners after a connection drop.
   - Buffers `emit()` calls while offline (Offline Queue) and flushes them upon restoration.
3. **Heartbeat Watchdog**
   - Client-side monitor for server Pings.
   - Triggers proactive reconnection if the server "goes silent" (Network/Proxy check).
4. **Fluent API**
   ```javascript
   const socket = new MonkeysSocket('ws://localhost:9000');
   socket.on('chat:message', (data) => { ... });
   socket.emit('chat:send', { msg: 'Hello' });
   ```

## 📦 Phase 6: Framework Integrations
- Automated client injection middleware.
- Dashboard for monitoring active connections and message throughput.

## 🏗️ Developer Notes
- **Standards**: Strictly follows [MonkeysLegion v2 Code Standards](CODE_STANDARDS.md).
- **Environment**: Requires `pcntl` and `posix` for integration testing.
- **Testing**: PHPUnit 13.2+ with target 80%+ coverage and PHPStan Level 9.

---
*Last Updated: 2026-04-22*
