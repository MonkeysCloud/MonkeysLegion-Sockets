<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Handshake;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\HandshakeException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HandshakeNegotiatorTest
 * 
 * Unit tests for verifying the RFC 6455 handshake negotiation logic.
 * Adheres to the "Zero Tolerance" policy for notices and deprecations.
 */
#[CoversClass(HandshakeNegotiator::class)]
final class HandshakeNegotiatorTest extends TestCase
{
    #[Test]
    public function it_successfully_negotiates_handshake(): void
    {
        $factory = $this->createMock(ResponseFactoryInterface::class);
        $negotiator = new HandshakeNegotiator($factory);

        /** @var ServerRequestInterface&Stub $request */
        $request = $this->createStub(ServerRequestInterface::class);
        /** @var ResponseInterface&Stub $response */
        $response = $this->createStub(ResponseInterface::class);

        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')
            ->willReturnCallback(fn(string $name) => match ($name) {
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
                'Sec-WebSocket-Version' => '13',
                default => ''
            });
        
        $request->method('hasHeader')->willReturnCallback(fn(string $name) => $name === 'Sec-WebSocket-Key');

        $factory->expects($this->once())
            ->method('createResponse')
            ->with(101)
            ->willReturn($response);
        
        $response->method('withHeader')
            ->willReturnCallback(function(string $name, mixed $value) use ($response) {
                if ($name === 'Sec-WebSocket-Accept') {
                    $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', (string) $value);
                }
                return $response;
            });

        $result = $negotiator->negotiate($request);
        $this->assertSame($response, $result);
    }

    #[Test]
    public function it_throws_exception_if_not_get_request(): void
    {
        /** @var ServerRequestInterface&Stub $request */
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');

        $factory = $this->createStub(ResponseFactoryInterface::class);
        $negotiator = new HandshakeNegotiator($factory);

        $this->expectException(HandshakeException::class);
        $this->expectExceptionMessage('Handshake must be a GET request');

        $negotiator->negotiate($request);
    }

    #[Test]
    public function it_throws_exception_if_missing_upgrade_header(): void
    {
        /** @var ServerRequestInterface&Stub $request */
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')->willReturnCallback(fn($name) => $name === 'Upgrade' ? 'none' : '');

        $factory = $this->createStub(ResponseFactoryInterface::class);
        $negotiator = new HandshakeNegotiator($factory);

        $this->expectException(HandshakeException::class);
        $this->expectExceptionMessage('Missing "Upgrade: websocket" header');

        $negotiator->negotiate($request);
    }

    #[Test]
    public function it_throws_exception_if_authentication_fails(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')->willReturnCallback(fn($n) => match ($n) {
            'Sec-WebSocket-Version' => '13',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            default => ''
        });
        $request->method('hasHeader')->willReturn(true);

        $authenticator = $this->createStub(\MonkeysLegion\Sockets\Contracts\AuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(null);

        $factory = $this->createStub(ResponseFactoryInterface::class);
        $negotiator = new HandshakeNegotiator($factory, $authenticator);

        $this->expectException(HandshakeException::class);
        $this->expectExceptionMessage('Authentication failed');

        $negotiator->negotiate($request);
    }

    #[Test]
    public function it_successfully_authenticates(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')->willReturnCallback(fn($n) => match ($n) {
            'Sec-WebSocket-Version' => '13',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'key123',
            default => ''
        });
        $request->method('hasHeader')->willReturn(true);

        $authenticator = $this->createStub(\MonkeysLegion\Sockets\Contracts\AuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn('user_789');

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturn($response);

        $factory = $this->createStub(ResponseFactoryInterface::class);
        $factory->method('createResponse')->willReturn($response);
        
        $negotiator = new HandshakeNegotiator($factory, $authenticator);
        $result = $negotiator->negotiate($request);
        
        $this->assertSame($response, $result);
    }

    #[Test]
    public function it_throws_exception_if_missing_connection_header(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')->willReturnCallback(fn($n) => match($n) {
            'Upgrade' => 'websocket',
            default => ''
        });

        $negotiator = new HandshakeNegotiator($this->createStub(ResponseFactoryInterface::class));
        $this->expectException(HandshakeException::class);
        $this->expectExceptionMessage('Missing "Connection: Upgrade" header');
        $negotiator->negotiate($request);
    }

    #[Test]
    public function it_throws_exception_if_missing_security_key(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')->willReturnCallback(fn($n) => match($n) {
            'Upgrade' => 'websocket',
            'Connection' => 'upgrade',
            default => ''
        });
        $request->method('hasHeader')->willReturn(false);

        $negotiator = new HandshakeNegotiator($this->createStub(ResponseFactoryInterface::class));
        $this->expectException(HandshakeException::class);
        $this->expectExceptionMessage('Missing "Sec-WebSocket-Key" header');
        $negotiator->negotiate($request);
    }

    #[Test]
    public function it_throws_exception_if_unsupported_version(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')->willReturnCallback(fn($n) => match($n) {
            'Upgrade' => 'websocket',
            'Connection' => 'upgrade',
            'Sec-WebSocket-Version' => '99',
            default => ''
        });
        $request->method('hasHeader')->willReturn(true);

        $negotiator = new HandshakeNegotiator($this->createStub(ResponseFactoryInterface::class));
        $this->expectException(HandshakeException::class);
        $this->expectExceptionMessage('Only WebSocket version 13 is supported');
        $negotiator->negotiate($request);
    }
}
