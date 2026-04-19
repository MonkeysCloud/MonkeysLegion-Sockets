<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use MonkeysLegion\Sockets\Contracts\AuthenticatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * QueryTokenAuthenticator
 * 
 * Simple authenticator that looks for a 'token' in the query string.
 * This is the fallback pattern for browser-based WebSockets which 
 * cannot always send custom headers during the handshake.
 */
class QueryTokenAuthenticator implements AuthenticatorInterface
{
    /**
     * @param string $correctToken The secret token expected in the query param
     */
    public function __construct(
        private readonly string $correctToken,
        private readonly string $queryParam = 'token'
    ) {}

    public function authenticate(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();
        $token = $params[$this->queryParam] ?? null;

        return $token === $this->correctToken ? $token : null;
    }
}
