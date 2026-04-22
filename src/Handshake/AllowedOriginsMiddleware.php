<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use MonkeysLegion\Sockets\Contracts\HandshakeMiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * AllowedOriginsMiddleware
 * 
 * Protects the WebSocket server from Cross-Site WebSocket Hijacking (CSWH)
 * by verifying the 'Origin' header against a whitelist of allowed domains.
 */
class AllowedOriginsMiddleware implements HandshakeMiddlewareInterface
{
    /**
     * @param string[] $allowedOrigins List of allowed origins (e.g. ['http://localhost:3000', '*.example.com'])
     */
    public function __construct(
        private array $allowedOrigins,
        private ResponseFactoryInterface $responseFactory
    ) {}

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        // If all origins are allowed, skip check
        if (\in_array('*', $this->allowedOrigins, true)) {
            return $next($request);
        }

        $origin = $request->getHeaderLine('Origin');

        // Browsers always send an Origin for WebSocket requests
        if ($origin === '') {
            return $next($request);
        }

        if (!$this->isOriginAllowed($origin)) {
            return $this->responseFactory->createResponse(403, 'Forbidden: Origin Not Allowed');
        }

        return $next($request);
    }

    private function isOriginAllowed(string $origin): bool
    {
        $origin = \strtolower($origin);

        foreach ($this->allowedOrigins as $allowed) {
            $allowed = \strtolower($allowed);

            // Exact match
            if ($origin === $allowed) {
                return true;
            }

            // Simple wildcard support (*.example.com)
            if (\str_contains($allowed, '*')) {
                $pattern = '/^' . \str_replace(['*', '.'], ['[^.]+', '\.'], $allowed) . '$/';
                if (\preg_match($pattern, $origin)) {
                    return true;
                }
            }
        }

        return false;
    }
}
