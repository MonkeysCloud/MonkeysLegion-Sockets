<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use MonkeysLegion\Sockets\Contracts\HandshakeMiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * IpFilterMiddleware
 * 
 * Rejects handshake requests from blocked IPs or non-whitelisted IPs.
 */
class IpFilterMiddleware implements HandshakeMiddlewareInterface
{
    /**
     * @param string[] $blockedIps
     * @param string[] $allowedIps If not empty, only these IPs are allowed.
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly array $blockedIps = [],
        private readonly array $allowedIps = []
    ) {}

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        // 1. Check blocklist
        if (\in_array($ip, $this->blockedIps, true)) {
            return $this->responseFactory->createResponse(403, 'IP Blocked');
        }

        // 2. Check allowlist (if provided)
        if (!empty($this->allowedIps) && !\in_array($ip, $this->allowedIps, true)) {
            return $this->responseFactory->createResponse(403, 'IP Not Whitelisted');
        }

        return $next($request);
    }
}
