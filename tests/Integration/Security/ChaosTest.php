<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\HandshakeException;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ChaosTest
 * 
 * Adversarial tests designed to break the library's Phase 1 components.
 */
final class ChaosTest extends TestCase
{
    private HandshakeNegotiator $negotiator;

    protected function setUp(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturn($response);
        
        $factory = $this->createStub(ResponseFactoryInterface::class);
        $factory->method('createResponse')->willReturn($response);
        
        $this->negotiator = new HandshakeNegotiator($factory);
    }

    #[Test]
    #[DataProvider('malformedHandshakeProvider')]
    public function it_rejects_malformed_handshakes(array $headers, string $expectedError): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')->willReturnCallback(fn($name) => $headers[$name] ?? '');
        $request->method('hasHeader')->willReturnCallback(fn($name) => isset($headers[$name]));

        if ($expectedError !== '') {
            $this->expectException(HandshakeException::class);
            $this->expectExceptionMessage($expectedError);
        }

        $result = $this->negotiator->negotiate($request);
        
        if ($expectedError === '') {
            $this->assertNotNull($result);
        }
    }

    public static function malformedHandshakeProvider(): array
    {
        return [
            'Missing version' => [
                ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ=='],
                'Only WebSocket version 13 is supported'
            ],
            'Wrong version' => [
                ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==', 'Sec-WebSocket-Version' => '8'],
                'Only WebSocket version 13 is supported'
            ],
            'Missing key' => [
                ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Version' => '13'],
                'Missing "Sec-WebSocket-Key" header'
            ],
            'Invalid connection header' => [
                ['Upgrade' => 'websocket', 'Connection' => 'keep-alive', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==', 'Sec-WebSocket-Version' => '13'],
                'Missing "Connection: Upgrade" header'
            ],
            'Lowercase upgrade' => [
                ['Upgrade' => 'WEBSOCKET', 'Connection' => 'Upgrade', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==', 'Sec-WebSocket-Version' => '13'],
                '' // Should pass
            ],
        ];
    }

    #[Test]
    public function it_handles_ridiculous_payload_lengths_gracefully(): void
    {
        $processor = new FrameProcessor();
        
        // Claim 9 Exabytes
        $bogusFrame = \chr(0x81) . \chr(0x7F) . \pack('J', 9223372036854775807);
        
        // Should return null (incomplete data) but not crash
        $frame = $processor->decode($bogusFrame);
        $this->assertNull($frame);
    }

    #[Test]
    public function it_rejects_truncated_frames(): void
    {
        $processor = new FrameProcessor();
        $truncated = \chr(0x81); // Only 1 byte, minimum is 2
        
        $frame = $processor->decode($truncated);
        $this->assertNull($frame);
    }
}
