<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use MonkeysLegion\Sockets\Contracts\HandshakeMiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * RateLimitMiddleware
 * 
 * Protects the WebSocket server from connection floods.
 * Basic implementation using a simple memory-based counter.
 * For production usage, this should be backed by Redis.
 */
class RateLimitMiddleware implements HandshakeMiddlewareInterface
{
    private array $hits = [];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly int $maxAttempts = 10,
        private readonly int $window = 60 // Seconds
    ) {}

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $now = \time();

        // 1. Cleanup old hits
        $this->hits[$ip] = \array_filter(
            $this->hits[$ip] ?? [],
            fn($timestamp) => $timestamp > ($now - $this->window)
        );

        // 2. Check threshold
        if (\count($this->hits[$ip]) >= $this->maxAttempts) {
            return $this->responseFactory->createResponse(429, 'Too Many Connection Attempts')
                ->withHeader('Retry-After', (string) $this->window);
        }

        // 3. Record hit and proceed
        $this->hits[$ip][] = $now;

        return $next($request);
    }
}
