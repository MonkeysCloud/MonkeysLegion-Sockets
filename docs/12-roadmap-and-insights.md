# Architectural Maturity & Roadmap

This document outlines the current architectural evaluation of the MonkeysLegion Sockets stack, versioning strategies, and insights into future enhancements.

## Evaluating

The current state of the backend socket engine is exceptionally robust.

**Why ?**
1. **Decoupling:** The framework flawlessly separates the transport layer (`Stream`/`React`/`Swoole`), the state layer (`Registry`), the authorization layer (`Pipeline`), and the IPC layer (`Broadcaster`). This is an enterprise-grade architecture pattern.
2. **Security-First:** Pushing authentication cleanly to the HTTP handshake layer using `HandshakeNegotiator` prevents the server from allocating WebSocket memory resources to malicious clients. The fail-closed `AuthorizerPipeline` and CSWH protections harden it further.
3. **Resilience:** Integrations test verify that the engine easily survives harsh network conditions, "Infinite Idle" attacks, and invalid framing without leaking memory.
4. **Ergonomics:** Semantic channels (`joinPublic`, `joinPrivate`, `joinPresence`) directly rival standard industry implementations like Laravel Echo, offering heavy capabilities with a natively typed PHP footprint.

**Why not perfect or in another word not enough:**
The missing fraction represents the edge cases forged only in the fires of heavy production traffic, such as unforeseen TCP packet-fragmentation issues at 100,000+ connections or Redis backpressure deadlocks under extreme multi-gigabit throughput. 

## Future Versioning Insights

### Backend: Major Release (v2.0.0)
The backend should be released as a **Major Version (v2.0.0)**. 
- **Breaking Changes:** The `BroadcasterInterface` contract received extensive additions (`publicChannel()`, `privateChannel()`, `channel()`, `emit()`, `raw()`). Standard Semantic Versioning (SemVer) mandates that any interface updates that could break custom user-land implementations constitute a major release.
- **Paradigm Shift:** The internal transition to leveraging the `RoomManager` for targeted Semantic Channels is a large architectural leap from legacy generic `join()` handling.


---

## 🔮 Future Insights: What's Next?

To push this architecture from a 9/10 to the absolute peak of real-time server technology, the roadmap should focus on three advanced objectives:

### 1. Observability & Metrics (Prometheus / Grafana)
In distributed production setups, "blind" WebSocket servers represent operational danger. 
**Goal:** Implement a `MetricsExporterInterface`. We should expose a hidden HTTP or sidecar port (e.g., `http://127.0.0.1:8081/metrics`) to emit OpenTelemetry/Prometheus metrics:
- Active connections per worker
- Messages decoded per second
- Memory usage trends
- Broadcast latency timings

### 2. Payload Compression (`permessage-deflate`)
Currently, frames are delivered as raw text or binary bytes.
**Goal:** Implement the standard WebSocket `permessage-deflate` extension during HTTP handshake negotiation. This utilizes Zlib for real-time stream compression, enabling massive bandwidth savings (60-80% reduction) for high-frequency JSON payloads.

### 3. Redis Sharding & Horizontal Scaling
Currently, the `RedisBroadcaster` consumes a single monolithic Pub/Sub channel (`ml_sockets:broadcast`).
**Goal:** If the application triggers millions of specific events per second, a single Redis core will hit a CPU bottleneck. Implementing Sharding (e.g., hashing the destination Tag to publish onto different Redis nodes) will unlock virtually unlimited horizontal scalability for enormous enterprise fleets.

---
*MonkeysLegion Architecture Team*
