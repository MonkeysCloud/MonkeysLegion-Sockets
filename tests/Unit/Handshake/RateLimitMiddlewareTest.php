<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Handshake;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Handshake\RateLimitMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RateLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function it_blocks_after_exceeding_max_attempts(): void
    {
        $factory = $this->createMock(ResponseFactoryInterface::class);
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '1.1.1.1']);
        $response->method('withHeader')->willReturn($response);

        // Limit to 2 attempts
        $middleware = new RateLimitMiddleware($factory, 2, 60);

        $coreResponse = $this->createStub(ResponseInterface::class);

        // 1st attempt: success
        $middleware->handle($request, fn() => $coreResponse);
        
        // 2nd attempt: success
        $middleware->handle($request, fn() => $coreResponse);

        // 3rd attempt: blocked
        $factory->expects($this->once())
            ->method('createResponse')
            ->with(429, 'Too Many Connection Attempts')
            ->willReturn($response);

        $result = $middleware->handle($request, fn() => $this->fail('Should be throttled'));
        $this->assertSame($response, $result);
    }
}
