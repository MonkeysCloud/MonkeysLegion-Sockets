<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use MonkeysLegion\Sockets\Contracts\HandshakeMiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * MiddlewarePipeline
 * 
 * Orchestrates the execution of a chain of handshake middlewares.
 */
class MiddlewarePipeline
{
    /**
     * @param HandshakeMiddlewareInterface[] $middlewares
     */
    public function __construct(
        private array $middlewares = []
    ) {}

    public function add(HandshakeMiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Process the request through the onion layers.
     */
    public function process(ServerRequestInterface $request, callable $core): ResponseInterface
    {
        $pipeline = \array_reduce(
            \array_reverse($this->middlewares),
            function ($next, $middleware) {
                return function ($request) use ($next, $middleware) {
                    return $middleware->handle($request, $next);
                };
            },
            $core
        );

        return $pipeline($request);
    }
}
