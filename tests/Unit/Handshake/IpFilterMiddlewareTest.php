<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Handshake;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Handshake\IpFilterMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class IpFilterMiddlewareTest extends TestCase
{
    #[Test]
    public function it_blocks_blacklisted_ips(): void
    {
        $factory = $this->createMock(ResponseFactoryInterface::class);
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '1.2.3.4']);

        $factory->expects($this->once())
            ->method('createResponse')
            ->with(403, 'IP Blocked')
            ->willReturn($response);

        $middleware = new IpFilterMiddleware($factory, ['1.2.3.4']);
        
        $result = $middleware->handle($request, fn() => $this->fail('Next should not be called'));
        
        $this->assertSame($response, $result);
    }

    #[Test]
    public function it_allows_whitelisted_ips(): void
    {
        $factory = $this->createStub(ResponseFactoryInterface::class);
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

        $middleware = new IpFilterMiddleware($factory, [], ['127.0.0.1']);
        
        $result = $middleware->handle($request, fn() => $response);
        
        $this->assertSame($response, $result);
    }

    #[Test]
    public function it_blocks_non_whitelisted_ips(): void
    {
        $factory = $this->createMock(ResponseFactoryInterface::class);
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '8.8.8.8']);

        $factory->expects($this->once())
            ->method('createResponse')
            ->with(403, 'IP Not Whitelisted')
            ->willReturn($response);

        $middleware = new IpFilterMiddleware($factory, [], ['127.0.0.1']);
        
        $result = $middleware->handle($request, fn() => $this->fail('Next should not be called'));
        
        $this->assertSame($response, $result);
    }
}
