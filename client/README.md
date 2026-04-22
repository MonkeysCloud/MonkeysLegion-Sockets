# MonkeysSocket JS Client

🚀 **MonkeysSocket** is a production-grade, zero-dependency JavaScript client library for the MonkeysLegion Sockets ecosystem. Designed for extreme performance and reliability, it provides an adaptive reconnection engine, offline message queuing, and a robust event-driven API.

## 📦 Features

- **Zero-Dependency**: No external heavy libraries, pure modern JavaScript.
- **Adaptive Reconnection**: Exponential backoff with jitter to prevent server thundering herds.
- **Offline Queuing**: Automatically queues messages if the connection is lost and flushes them upon restoration.
- **EventEmitter API**: Intuitive event-based orchestration for your real-time application.
- **Universal Compatibility**: Works in modern Browsers and Node.js environments (with `ws` polyfill).
- **Security-First**: Enforces strict origin checks and protocol standard compliance.

---

## 🛠️ Installation

Simply include the source in your project, or if you're using a build system:

```javascript
import MonkeysSocket from './src/monkeys-sockets.js';
```

For **Node.js** usage, ensure you have a `WebSocket` polyfill available:

```javascript
import WebSocket from 'ws';
global.WebSocket = WebSocket;
```

### 🐒 MonkeysLegion (PHP) Integration
If you are using this inside a MonkeysLegion PHP application, you don't need to manually copy files. Simply run:

```bash
php ml socket:install
```
The installer will ask if you want to publish the JS assets to `public/js/vendor/monkeys-sockets.js` automatically.

---

## 🚀 Usage

### basic setup
```javascript
const socket = new MonkeysSocket('ws://your-server:9102', {
    autoConnect: true,
    reconnect: true,
    socketOptions: {
        origin: 'http://your-app.com'
    }
});

// Handling events
socket.on('connect', () => {
    console.log('Connected to MonkeysLegion!');
});

// Emitting data
socket.emit('chat:message', { text: 'Hello Monkeys!' });

// Listening for server events
socket.on('chat:message', (data) => {
    console.log('Received:', data.text);
});
```

### Configuration Options
| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `autoConnect` | `Boolean` | `true` | Connect immediately upon instantiation. |
| `reconnect` | `Boolean` | `true` | Enable automatic adaptive reconnection. |
| `maxReconnectAttempts` | `Number` | `Infinity` | Cap the number of retry attempts. |
| `initialDelay` | `Number` | `1000` | Starting delay for backoff (ms). |
| `maxDelay` | `Number` | `30000` | Maximum cap for backoff delay (ms). |
| `socketOptions` | `Object` | `{}` | Native WebSocket constructor options (Protocols, Headers, etc.). |

---

## 📡 Event System

MonkeysSocket uses an internal `EventEmitter` to bridge the gap between low-level socket events and high-level application logic.

### Standard Events
- `connect`: Fired when the connection is established.
- `disconnect`: Fired when the connection is closed or lost.
- `error`: Fired on transport-level errors.
- `message`: Fired for *every* incoming JSON message from the server.
- `reconnect`: Fired when a reconnection attempt starts.
- `reconnected`: Fired when a reconnection is successful.

---

## 🛡️ Security & Performance

### Origin Verification
The client properly passes `Origin` headers through `socketOptions`. Ensure your server configuration matches the allowed origins to prevent unauthorized access.

### Message Limits
The server enforces a 10MB default message limit. While MonkeysSocket handles large payloads, it is recommended to keep message sizes small to maintain low latency.

### Reconnection Jitter
MonkeysSocket applies a random jitter to reconnection delays. This is critical in production to ensure that if a server goes down, thousands of clients don't all try to reconnect at the exact same millisecond.

---

## 🧪 Testing

The client includes a comprehensive test suite using **Vitest**:

```bash
cd client
npm test
```

Includes Unit Tests for the state machine and **Integration Tests** against a real standalone PHP transport layer.

---

## 📜 License
MIT - Part of the MonkeysLegion Framework.
