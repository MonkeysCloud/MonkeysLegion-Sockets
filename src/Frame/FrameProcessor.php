<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Frame;

/**
 * FrameProcessor
 * 
 * Responsible for encoding data into WebSocket frames and decoding
 * raw binary data back into Frame objects. Follows RFC 6455 structure.
 */
final readonly class FrameProcessor
{
    /**
     * Encode a payload into a WebSocket frame.
     */
    public function encode(
        string $payload,
        int $opcode = 0x1,
        bool $isFinal = true,
        bool $mask = false
    ): string {
        // 1. Prepare the First Byte (FIN flag + OpCode)
        // FIN is bit 0, OpCode is bits 4-7
        $firstByte = ($isFinal ? 0x80 : 0x00) | ($opcode & 0x0f);
        $header = \chr($firstByte);

        // 2. Determine Payload Length and Mask Bit
        $payloadLength = \strlen($payload);
        $maskBit = $mask ? 0x80 : 0x00;

        // Small payloads (<= 125 bytes) fit in the 7-bit length field
        if ($payloadLength <= 125) {
            $header .= \chr($maskBit | $payloadLength);
        } 
        // Medium payloads (<= 65535) use code 126 followed by 16 bits
        elseif ($payloadLength <= 65535) {
            $header .= \chr($maskBit | 126) . \pack('n', $payloadLength);
        } 
        // Large payloads use code 127 followed by 64 bits
        else {
            $header .= \chr($maskBit | 127) . \pack('J', $payloadLength);
        }

        // 3. Apply Masking if required (Standard for Client -> Server)
        if ($mask) {
            $maskingKey = \random_bytes(4);
            $header .= $maskingKey;
            $payload = $this->applyMask($payload, $maskingKey);
        }

        return $header . $payload;
    }

    /**
     * Decode a raw binary frame into a Frame object.
     */
    public function decode(string $raw): ?Frame
    {
        // WebSocket frames require at least 2 bytes (Header + Length)
        if (\strlen($raw) < 2) {
            return null;
        }

        // 1. Parse First Byte (FIN and OpCode)
        $firstByte = \ord($raw[0]);
        $secondByte = \ord($raw[1]);

        $isFinal = (bool) ($firstByte & 0x80);
        $opcode = $firstByte & 0x0f;

        // 2. Parse Second Byte (Mask and Payload Length)
        $isMasked = (bool) ($secondByte & 0x80);
        $payloadLength = $secondByte & 0x7f;

        $offset = 2; // Start after the first 2 bytes

        // 3. Handle Extended Payload Lengths (126 or 127)
        if ($payloadLength === 126) {
            $unpacked = \unpack('n', \substr($raw, $offset, 2));
            $payloadLength = \is_array($unpacked) ? (int) $unpacked[1] : 0;
            $offset += 2;
        } elseif ($payloadLength === 127) {
            $unpacked = \unpack('J', \substr($raw, $offset, 8));
            $payloadLength = \is_array($unpacked) ? (int) $unpacked[1] : 0;
            $offset += 8;
        }

        // 4. Security Check: verify we have the full frame in memory
        if (\strlen($raw) < $offset + (int) $payloadLength) {
            return null;
        }

        // 5. Handle Masking Key and Payload extraction
        $payload = \substr($raw, $offset, (int) $payloadLength);

        if ($isMasked) {
            // Check for potential truncated header for the mask itself
            if (\strlen($raw) < $offset + 4) {
                return null;
            }

            $maskingKey = (string) \substr($raw, $offset, 4);
            $offset += 4;
            
            // Re-read payload starting after the 4-byte mask
            $payload = \substr($raw, $offset, (int) $payloadLength);
            $payload = $this->applyMask($payload, $maskingKey);
            
            return new Frame($payload, $opcode, $isFinal, $isMasked, $maskingKey);
        }

        return new Frame($payload, $opcode, $isFinal, $isMasked);
    }

    /**
     * XOR mask application for WebSocket security.
     * Each byte of payload is XORed with (index % 4) byte of the key.
     */
    private function applyMask(string $data, string $key): string
    {
        $payload = '';
        $keyLength = \strlen($key);
        if ($keyLength === 0) {
            return $data;
        }

        for ($i = 0; $i < \strlen($data); $i++) {
            $payload .= $data[$i] ^ $key[$i % 4];
        }
        return $payload;
    }
}
