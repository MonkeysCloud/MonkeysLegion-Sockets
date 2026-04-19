<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HandshakeMiddlewareInterface
 * 
 * Defines logic that can run before or during the WebSocket handshake 
 * to filter, limit, or augment the connection attempt.
 */
interface HandshakeMiddlewareInterface
{
    /**
     * Handle the handshake request.
     * 
     * @param ServerRequestInterface $request
     * @param callable $next Returns ResponseInterface
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface;
}
