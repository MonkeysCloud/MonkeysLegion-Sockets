<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Handshake;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Handshake\JwtAuthenticator;
use Psr\Http\Message\ServerRequestInterface;

final class JwtAuthenticatorTest extends TestCase
{
    #[Test]
    public function it_authenticates_with_valid_jwt(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer valid_token');

        $verifier = function($token) {
            return $token === 'valid_token' ? 'user_456' : null;
        };

        $authenticator = new JwtAuthenticator($verifier);
        
        $this->assertEquals('user_456', $authenticator->authenticate($request));
    }

    #[Test]
    public function it_returns_null_on_invalid_token(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer invalid');

        $authenticator = new JwtAuthenticator(fn() => null);
        
        $this->assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function it_returns_null_on_missing_header(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');

        $authenticator = new JwtAuthenticator(fn() => 'fail');
        
        $this->assertNull($authenticator->authenticate($request));
    }
}
