<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

use Psr\Http\Message\ServerRequestInterface;

/**
 * AuthenticatorInterface
 * 
 * Defines the contract for validating a client during the WebSocket handshake.
 */
interface AuthenticatorInterface
{
    /**
     * Authenticate the request. 
     * Returns true if allowed, false or throws exception if denied.
     */
    public function authenticate(ServerRequestInterface $request): bool;
}
