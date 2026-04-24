# Frame Processing Layer

The Frame layer is responsible for **encoding and decoding WebSocket frames** according to RFC 6455 §5. It handles the binary wire format, masking, fragmentation, and message reassembly. This layer sits between the raw TCP stream and the application logic — the transport driver reads bytes, this layer converts them into meaningful messages.

---

## Components

| Class | Purpose |
|:---|:---|
| `Frame` | Immutable value object representing a single WebSocket frame |
| `FrameProcessor` | Encodes/decodes raw binary data to/from `Frame` objects |
| `MessageAssembler` | Reassembles fragmented frames into complete messages |

---

## Frame

**Namespace:** `MonkeysLegion\Sockets\Frame`  
**Implements:** `MessageInterface`

An immutable value object representing a single WebSocket frame. Every decoded frame provides access to its payload, opcode, masking state, and consumed byte length.

### Constructor

```php
public function __construct(
    private readonly string $payload,
    private readonly int $opcode = 0x1,
    private readonly bool $isFinal = true,
    private readonly bool $isMasked = false,
    private readonly ?string $maskingKey = null,
    private readonly int $consumedLength = 0,
)
```

### Methods

| Method | Return Type | Description |
|:---|:---|:---|
| `getPayload()` | `string` | The decoded payload data |
| `getOpcode()` | `int` | The frame opcode (see table below) |
| `isFinal()` | `bool` | Whether this is the last fragment of a message |
| `isBinary()` | `bool` | Whether this is a binary frame (opcode `0x2`) |
| `isMasked()` | `bool` | Whether the frame payload was masked |
| `getMaskingKey()` | `?string` | The 4-byte masking key, if applicable |
| `getConsumedLength()` | `int` | Total bytes consumed from the buffer (header + payload) |

### WebSocket Opcodes (RFC 6455 §5.2)

| Opcode | Hex | Meaning |
|:---|:---|:---|
| Continuation | `0x0` | Fragment continuation |
| Text | `0x1` | UTF-8 text data |
| Binary | `0x2` | Binary data |
| Close | `0x8` | Connection close |
| Ping | `0x9` | Keep-alive ping |
| Pong | `0xA` | Keep-alive pong response |

---

## FrameProcessor

**Namespace:** `MonkeysLegion\Sockets\Frame`  
**Type:** `final readonly class`

The binary codec for WebSocket frames. Handles encoding payloads into wire format and decoding raw bytes back into `Frame` objects.

### Method: `encode(string $payload, int $opcode = 0x1, bool $isFinal = true, bool $mask = false): string`

Encodes a payload into a WebSocket frame ready for transmission.

**Parameters:**

| Parameter | Type | Default | Description |
|:---|:---|:---|:---|
| `$payload` | `string` | — | The data to encode |
| `$opcode` | `int` | `0x1` (Text) | The frame opcode |
| `$isFinal` | `bool` | `true` | Whether this is the last fragment |
| `$mask` | `bool` | `false` | Whether to apply client-side masking |

**Encoding Logic:**

1. **First byte:** Sets the FIN bit (0x80) and the opcode.
2. **Length encoding:**
   - `<= 125 bytes`: Length fits in 7-bit field.
   - `<= 65535 bytes`: Uses code `126` + 16-bit unsigned integer.
   - `> 65535 bytes`: Uses code `127` + 64-bit unsigned integer.
3. **Masking:** If enabled, generates a random 4-byte key and XORs the payload.

```php
$processor = new FrameProcessor();

// Encode a text message
$frame = $processor->encode('Hello, World!');

// Encode a binary frame
$frame = $processor->encode($binaryData, opcode: 0x2);

// Encode a Ping frame
$frame = $processor->encode('', opcode: 0x9);

// Encode a client-to-server frame (masked)
$frame = $processor->encode('Hello', mask: true);
```

### Method: `decode(string $raw): ?Frame`

Decodes raw binary data into a `Frame` object.

**Returns:** A `Frame` if enough data is available, or `null` if the buffer is incomplete.

**Throws:** `RuntimeException` with code `1007` if a text frame contains invalid UTF-8 (as required by RFC 6455 §5.6).

**Decoding Logic:**

1. Parses FIN bit and opcode from the first byte.
2. Parses mask bit and payload length from the second byte.
3. Handles extended length (16-bit or 64-bit).
4. Verifies the buffer has enough data for the full frame.
5. Extracts and unmasks the payload if masked.
6. Validates UTF-8 encoding for text frames.

```php
$processor = new FrameProcessor();

$frame = $processor->decode($rawBytes);

if ($frame !== null) {
    echo $frame->getPayload();           // "Hello"
    echo $frame->getOpcode();            // 1 (Text)
    echo $frame->getConsumedLength();    // Total bytes consumed
}
```

### Masking Algorithm

WebSocket masking uses a simple XOR cipher with a 4-byte key. The implementation uses PHP's native string XOR operator for optimal performance:

```php
// Internal implementation (optimized)
$data ^ str_repeat($key, (int) ceil(strlen($data) / 4));
```

This is required by RFC 6455 for all client-to-server frames and provides protection against cache poisoning attacks on intermediary proxies.

---

## MessageAssembler

**Namespace:** `MonkeysLegion\Sockets\Frame`

Handles **message fragmentation** — the reassembly of multiple frames (with `FIN = 0`) into a single complete message. This is essential for handling large payloads that are split across multiple WebSocket frames.

### Constructor

```php
public function __construct(
    private readonly int $maxMessageSize = 10 * 1024 * 1024 // 10MB
)
```

| Parameter | Type | Default | Description |
|:---|:---|:---|:---|
| `$maxMessageSize` | `int` | `10485760` | Maximum allowed reassembled message size in bytes |

### Method: `assemble(string|int $streamId, Frame $frame): ?Frame`

Processes incoming frames and returns a complete `Frame` when the full message has been assembled.

**Returns:**
- A complete `Frame` when the final fragment arrives.
- `null` when more fragments are needed.

**Throws:**
- `RuntimeException` if a new message frame arrives before the previous fragmented message is complete.
- `RuntimeException` if the assembled message exceeds `$maxMessageSize` (backpressure protection).

### Method: `clear(string|int $streamId): void`

Clears the buffer for a specific stream. Called automatically after successful assembly or when a connection is closed.

### Fragmentation Flow

```text
Client sends: "Hello, World!" split into 3 fragments

Frame 1: opcode=0x1, FIN=0, payload="Hello"     → assemble() returns null
Frame 2: opcode=0x0, FIN=0, payload=", "         → assemble() returns null
Frame 3: opcode=0x0, FIN=1, payload="World!"     → assemble() returns Frame("Hello, World!")
```

### Usage Example

```php
$assembler = new MessageAssembler(maxMessageSize: 5 * 1024 * 1024); // 5MB limit

// Called by the driver for each decoded frame
$complete = $assembler->assemble($streamId, $frame);

if ($complete !== null) {
    // Full message is ready
    echo $complete->getPayload();
}
```

### Security: Backpressure Protection

If a malicious client sends arbitrarily large fragmented messages to exhaust server memory, the assembler detects the overflow and immediately:

1. **Clears** the buffer for that stream.
2. **Throws** a `RuntimeException` with a descriptive message.

The driver catches this exception and closes the offending connection.
