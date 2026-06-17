# MonkeysLegion Sockets — package patch notes

Package: `monkeyscloud/monkeyslegion-sockets` (v1.1.x)

This document lists known gaps in the library and the acceptance criteria to verify each fix before release.

---

## Summary

| # | Issue | File(s) |
|---|--------|---------|
| 1 | Swoole driver skips handshake middleware | `src/Driver/SwooleDriver.php` |
| 2 | `socket:serve` does not start broadcast subscribers | `src/Cli/Command/SocketServerCommand.php` |
| 3 | React driver never runs the event loop | `src/Driver/ReactSocketDriver.php` |
| 4 | JSON envelope key mismatch (`data` vs `payload`) | `JsonMessageSerializer`, JS client |
| 5 | Browser `WebSocket` constructor uses invalid third argument | JS client |
| 6 | Broadcasting docs contradict wiring guidance | `docs/04-broadcasting.md` |

---

## 1. `SwooleDriver` does not run handshake middleware

**File:** `src/Driver/SwooleDriver.php`

**Problem:** Connections are accepted on Swoole’s `open` event without calling `HandshakeNegotiator`. Configured middleware (allowed origins, JWT/query-token auth, rate limits) never runs for the Swoole driver.

Stream and React drivers parse the HTTP upgrade and call `$this->negotiator->negotiate($request)`. Swoole does not.

**Suggested patch:**

- Inject `HandshakeNegotiator` in the constructor (same pattern as `ReactSocketDriver`).
- Register Swoole’s `handshake` event, build a PSR-7 request (including query params), call `$this->negotiator->negotiate()`, and write the resulting 101 or error response.

**Acceptance criteria**

- [x] With `AllowedOriginsMiddleware` configured, a handshake whose `Origin` is **not** whitelisted receives **403** (not 101).
- [x] With a JWT/query-token authenticator configured, a handshake **without** a valid token receives **400/401/403** (not 101).
- [x] A valid handshake (allowed origin + valid token when auth is enabled) returns **101 Switching Protocols** with a correct `Sec-WebSocket-Accept`.
- [x] PHPUnit coverage: at least one integration test exercising `SwooleDriver` + `HandshakeNegotiator` (mirror `HandshakeIntegrationTest` for `StreamSocketDriver`).

---

## 2. `socket:serve` does not start Unix/Redis subscriber

**Files:** `src/Cli/Command/SocketServerCommand.php`, `docs/04-broadcasting.md`

**Problem:** The CLI command only calls `$driver->listen()`. It does **not** start:

- `UnixSubscriber` when `broadcast = unix`
- `RedisSubscriber` when `broadcast = redis`

Documentation states the subscriber must be booted separately (fork, Swoole process, or worker script). Every consumer must reimplement IPC wiring.

**Suggested patch:** Add optional bootstrap in `SocketServerCommand` or a `BroadcastListenerInterface::attach(Server $server, BroadcastBridge $bridge)` hook:

| Driver | Recommended subscriber wiring |
|--------|--------------------------------|
| Swoole | `$server->addProcess(...)` + `$server->on('Message', ...)` |
| React | Non-blocking stream read on the event loop |
| Stream | `pcntl_fork` child or dedicated worker CLI |

**Acceptance criteria**

- [x] Running `socket:serve` with `broadcast=unix` creates the Unix socket and delivers a published message to connected WebSocket clients **without** extra custom code.
- [x] Running `socket:serve` with `broadcast=redis` subscribes to the configured channel and delivers messages to local connections.
- [x] Documentation describes one supported wiring path per driver (no “must fork separately” unless truly required).
- [x] Integration test: publish from `UnixBroadcaster` / `RedisBroadcaster` → message received by a connected test client after only `socket:serve`.

---

## 3. `ReactSocketDriver::listen()` never runs the event loop

**File:** `src/Driver/ReactSocketDriver.php`

**Problem:** `listen()` registers React socket callbacks but **never calls `Loop::run()`**, so the process exits unless something else runs the loop.

**Suggested patch:** End `listen()` with `Loop::run()`, or rename/document the method as non-blocking (`listenAsync()`) and require callers to run the loop.

**Acceptance criteria**

- [x] A minimal script that only calls `$driver->listen($host, $port)` stays alive and accepts WebSocket connections for at least 30 seconds.
- [x] Process exits cleanly on `SIGTERM` / `$driver->stop()` if stop is supported.
- [x] Existing React integration tests pass without an external loop runner.

---

## 4. JSON wire format: `data` vs `payload`

**Files:** `src/Serialization/JsonMessageSerializer.php`, JS client (`monkeys-sockets.js`)

**Problem:**

- Server serializer emits `{ "event", "data", "metadata", "timestamp" }`.
- JS client `emit()` sends `{ "event", "payload" }`.
- JS client `_onMessage` dispatches `data.payload`, so server messages using `data` are dropped unless the client is patched.

**Suggested patch:** Standardize on **`data`** (matches `JsonMessageSerializer` and `JsonFormatter`). Update the JS client to emit and dispatch `data`; optionally accept `payload` on decode for backward compatibility.

**Acceptance criteria**

- [x] Server → client: after `serialize('test', ['foo' => 'bar'])`, the JS client fires a `test` listener with `{ foo: 'bar' }`.
- [x] Client → server: after `socket.emit('ping', { ok: true })`, the PHP server decodes `event=ping` and `data/payload` containing `{ ok: true }`.
- [x] Unit tests updated for both directions; no duplicate or silent event drops.

---

## 5. JS client — browser `WebSocket` constructor

**File:** JS client `connect()`

**Problem:**

```javascript
this.socket = new WebSocket(this.url, protocols, this.options.socketOptions);
```

The browser `WebSocket` API supports only `(url)` and `(url, protocols)`. The third argument is non-standard (Node `ws` only). Browsers ignore it today, but it implies options (e.g. `origin`) that cannot be set from JavaScript.

**Suggested patch:** Use `new WebSocket(this.url, protocols)` in the browser build; document that `Origin` is sent by the browser from the page URL.

**Acceptance criteria**

- [x] Browser bundle calls `WebSocket` with at most two arguments.
- [x] Node build may keep extended options behind an environment check if needed.
- [x] No regression in connection success rate in Chrome/Firefox/Safari smoke tests.

---

## 6. Documentation contradictions

**File:** `docs/04-broadcasting.md`

**Problem:** One section states the Redis subscriber “runs inside the WebSocket server process”; another says the subscriber “MUST be booted as a parallel process.”

**Suggested patch:** Clarify per driver (see table in §2). Remove contradictory statements.

**Acceptance criteria**

- [x] A reader can follow the doc and choose one wiring approach per driver without conflicting instructions.
- [x] Examples match the behavior of `socket:serve` after §2 is fixed.

---

## Recommended PR checklist

1. [ ] `SwooleDriver`: inject `HandshakeNegotiator`, implement `handshake` event
2. [ ] `ReactSocketDriver`: run `Loop::run()` (or document async contract)
3. [x] Broadcast subscriber factory / CLI bootstrap for unix and redis
4. [ ] Align JS client envelope to `JsonMessageSerializer` (`data` key)
5. [ ] Fix JS `WebSocket` constructor for browser (≤2 args)
6. [x] Reconcile broadcasting documentation

