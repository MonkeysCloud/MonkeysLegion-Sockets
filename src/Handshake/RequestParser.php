<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use Psr\Http\Message\ServerRequestInterface;

/**
 * RequestParser
 * 
 * Internal utility to convert raw HTTP strings from the socket into internal PSR-7 requests.
 */
final class RequestParser
{
    /**
     * Parse a raw HTTP request string into a MinimalServerRequest.
     */
    public static function parse(string $raw): ServerRequestInterface
    {
        $lines = \explode("\r\n", $raw);
        $requestLine = \array_shift($lines);
        
        if (!$requestLine) {
            throw new HandshakeException('Empty request line');
        }

        $parts = \explode(' ', $requestLine);
        if (\count($parts) < 3) {
            throw new HandshakeException('Invalid request line');
        }

        [$method, $target, $version] = $parts;

        $headers = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if (empty($line)) continue; // Don't break on empty, just skip (more robust)
            
            $headerParts = \explode(': ', $line, 2);
            if (\count($headerParts) === 2) {
                $headers[$headerParts[0]] = $headerParts[1];
            }
        }
        
        return new MinimalServerRequest(
            $method, 
            $target, 
            $headers, 
            \str_starts_with($version, 'HTTP/') ? \substr($version, 5) : '1.1'
        );
    }
}
