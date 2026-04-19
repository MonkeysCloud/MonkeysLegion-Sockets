<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

use Psr\Http\Message\ServerRequestInterface;

/**
 * AuthenticatorInterface
 * 
 * Defines how to authenticate a WebSocket connection attempt 
 * during the initial HTTP handshake.
 */
interface AuthenticatorInterface
{
    /**
     * Authenticate the request.
     * Returns a string identifier (e.g. User ID) if successful, null otherwise.
     */
    public function authenticate(ServerRequestInterface $request): ?string;
}
