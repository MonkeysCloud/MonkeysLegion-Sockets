<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use MonkeysLegion\Sockets\Contracts\AuthenticatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * JwtAuthenticator
 * 
 * Verifies identity via a JSON Web Token provided in the Authorization header.
 */
class JwtAuthenticator implements AuthenticatorInterface
{
    /**
     * @param callable $verifier Function(string $token): ?string (returns user ID)
     */
    public function __construct(
        private $verifier,
        private readonly string $header = 'Authorization',
        private readonly string $prefix = 'Bearer '
    ) {}

    public function authenticate(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine($this->header);
        
        if (empty($header) || !\str_starts_with($header, $this->prefix)) {
            return null;
        }

        $token = \substr($header, \strlen($this->prefix));
        
        return ($this->verifier)($token);
    }
}
