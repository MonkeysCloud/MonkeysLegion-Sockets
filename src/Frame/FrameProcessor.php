<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Frame;

/**
 * FrameProcessor
 * 
 * Responsible for encoding data into WebSocket frames and decoding
 * raw binary data back into Frame objects including masking logic.
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
        $firstByte = ($isFinal ? 0x80 : 0x00) | ($opcode & 0x0f);
        $header = \chr($firstByte);

        $payloadLength = \strlen($payload);
        $maskBit = $mask ? 0x80 : 0x00;

        if ($payloadLength <= 125) {
            $header .= \chr($maskBit | $payloadLength);
        } elseif ($payloadLength <= 65535) {
            $header .= \chr($maskBit | 126) . \pack('n', $payloadLength);
        } else {
            $header .= \chr($maskBit | 127) . \pack('J', $payloadLength);
        }

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
        if (\strlen($raw) < 2) {
            return null;
        }

        $firstByte = \ord($raw[0]);
        $secondByte = \ord($raw[1]);

        $isFinal = (bool) ($firstByte & 0x80);
        $opcode = $firstByte & 0x0f;
        $isMasked = (bool) ($secondByte & 0x80);
        $payloadLength = $secondByte & 0x7f;

        $offset = 2;

        if ($payloadLength === 126) {
            $unpacked = \unpack('n', \substr($raw, $offset, 2));
            $payloadLength = \is_array($unpacked) ? (int) $unpacked[1] : 0;
            $offset += 2;
        } elseif ($payloadLength === 127) {
            $unpacked = \unpack('J', \substr($raw, $offset, 8));
            $payloadLength = \is_array($unpacked) ? (int) $unpacked[1] : 0;
            $offset += 8;
        }

        $payload = \substr($raw, $offset, (int) $payloadLength);

        if ($isMasked) {
            $maskingKey = (string) \substr($raw, $offset, 4);
            $offset += 4;
            $payload = \substr($raw, $offset, (int) $payloadLength);
            $payload = $this->applyMask($payload, $maskingKey);
            return new Frame($payload, $opcode, $isFinal, $isMasked, $maskingKey);
        }

        return new Frame($payload, $opcode, $isFinal, $isMasked);
    }

    /**
     * XOR mask application for WebSocket security.
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
