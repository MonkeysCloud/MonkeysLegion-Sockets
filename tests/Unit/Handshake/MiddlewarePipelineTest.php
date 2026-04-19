<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Handshake;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Handshake\MiddlewarePipeline;
use MonkeysLegion\Sockets\Contracts\HandshakeMiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MiddlewarePipelineTest extends TestCase
{
    #[Test]
    public function it_executes_middlewares_in_onion_order(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        
        $order = [];

        $mw1 = $this->createStub(HandshakeMiddlewareInterface::class);
        $mw1->method('handle')->willReturnCallback(function($req, $next) use (&$order) {
            $order[] = 'mw1_start';
            $res = $next($req);
            $order[] = 'mw1_end';
            return $res;
        });

        $mw2 = $this->createStub(HandshakeMiddlewareInterface::class);
        $mw2->method('handle')->willReturnCallback(function($req, $next) use (&$order) {
            $order[] = 'mw2_start';
            $res = $next($req);
            $order[] = 'mw2_end';
            return $res;
        });

        $pipeline = new MiddlewarePipeline([$mw1, $mw2]);
        
        $result = $pipeline->process($request, function($req) use (&$order, $response) {
            $order[] = 'core';
            return $response;
        });

        $this->assertSame($response, $result);
        $this->assertEquals([
            'mw1_start',
            'mw2_start',
            'core',
            'mw2_end',
            'mw1_end'
        ], $order);
    }

    #[Test]
    public function it_can_add_middlewares_fluently(): void
    {
        $pipeline = new MiddlewarePipeline();
        $mw = $this->createStub(HandshakeMiddlewareInterface::class);
        
        $result = $pipeline->add($mw);
        
        $this->assertSame($pipeline, $result);
    }
}
