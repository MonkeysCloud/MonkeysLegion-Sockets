# Protocol & Serialization

The Protocol layer defines how **application-level messages are formatted** for transmission over the WebSocket connection. It handles the conversion between structured PHP data and the wire format (JSON or MsgPack). The Serialization layer provides envelope-based message structures for inter-process communication.

---

## Components

| Class | Purpose |
|:---|:---|
| `FormatterInterface` | Contract for message format implementations |
| `JsonFormatter` | JSON-based message formatting (default) |
| `MsgPackFormatter` | MsgPack binary formatting (high performance) |
| `MessageSerializerInterface` | Contract for IPC message serialization |
| `JsonMessageSerializer` | JSON serializer for broadcast envelopes |
| `MessageEnvelope` | Typed value object for broadcast messages |

---

## FormatterInterface

**Namespace:** `MonkeysLegion\Sockets\Contracts`

```php
interface FormatterInterface
{
    public function format(string $event, mixed $data = [], array $meta = []): string;
    public function parse(string $payload): array;
}
```

| Method | Description |
|:---|:---|
| `format()` | Converts an event + data + meta into a wire-format string |
| `parse()` | Converts a wire-format string back into `['event', 'data', 'meta']` |

---

## JsonFormatter

**Namespace:** `MonkeysLegion\Sockets\Protocol`  
**Implements:** `FormatterInterface`

The default formatter. Produces human-readable JSON with automatic timestamp injection.

### `format(string $event, mixed $data = [], array $meta = []): string`

```php
$formatter = new JsonFormatter();

$wire = $formatter->format('chat:message', [
    'text' => 'Hello!',
    'from' => 'Alice'
]);
```

**Output:**
```json
{
    "event": "chat:message",
    "data": {
        "text": "Hello!",
        "from": "Alice"
    },
    "meta": {
        "t": 1714000000.123456
    }
}
```

The `meta.t` field is automatically injected with the current `microtime(true)` for latency measurement.

### `parse(string $payload): array`

```php
$parsed = $formatter->parse($jsonString);

echo $parsed['event']; // "chat:message"
echo $parsed['data'];  // ['text' => 'Hello!', 'from' => 'Alice']
echo $parsed['meta'];  // ['t' => 1714000000.123456]
```

### Error Handling

Both methods throw `RuntimeException` on invalid JSON, wrapping the underlying `JsonException` with a descriptive message.

---

## MsgPackFormatter

**Namespace:** `MonkeysLegion\Sockets\Protocol`  
**Implements:** `FormatterInterface`

A high-performance binary formatter using MsgPack. Produces smaller payloads and faster serialization compared to JSON.

### Requirements

- `ext-msgpack` PHP extension

### When to Use

| Scenario | Recommendation |
|:---|:---|
| Browser clients (JS) | Use `JsonFormatter` (native `JSON.parse`) |
| Server-to-server | Use `MsgPackFormatter` (smaller, faster) |
| Mobile/IoT clients | Use `MsgPackFormatter` (bandwidth savings) |
| Debugging | Use `JsonFormatter` (human-readable) |

---

## MessageEnvelope

**Namespace:** `MonkeysLegion\Sockets\Serialization`

A typed value object representing a broadcast message in transit between the application and the WebSocket worker.

```php
$envelope = new MessageEnvelope(
    type: 'tag',
    target: 'room:lobby',
    event: 'message',
    data: ['text' => 'Hello!'],
    timestamp: microtime(true)
);
```

### Properties

| Property | Type | Description |
|:---|:---|:---|
| `type` | `string` | `"tag"`, `"connection"`, or `"broadcast"` |
| `target` | `?string` | The specific tag or connection ID |
| `event` | `string` | The event name |
| `data` | `mixed` | The event payload |
| `timestamp` | `float` | Microsecond-precision timestamp |

---

## JsonMessageSerializer

**Namespace:** `MonkeysLegion\Sockets\Serialization`  
**Implements:** `MessageSerializerInterface`

Serializes and deserializes `MessageEnvelope` objects for inter-process communication (Redis Pub/Sub or Unix socket).

```php
$serializer = new JsonMessageSerializer();

// Serialize
$json = $serializer->serialize($envelope);

// Deserialize
$envelope = $serializer->deserialize($json);
```

---

## Configuration

The formatter is selected via the `sockets.mlc` configuration file:

```mlc
sockets {
    formatter ${WS_FORMATTER:-json},  # "json" or "msgpack"
}
```

The `SocketServiceProvider` automatically resolves the correct implementation and binds it to the `FormatterInterface` contract in the DI container.
