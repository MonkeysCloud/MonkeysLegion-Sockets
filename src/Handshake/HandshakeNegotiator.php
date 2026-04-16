<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HandshakeNegotiator
 * 
 * Negotiates the WebSocket handshake (RFC 6455) by validating
 * the PSR-7 Request and generating the 101 Switching Protocols Response.
 */
final readonly class HandshakeNegotiator
{
    /**
     * The WebSocket GUID defined in RFC 6455.
     * This unique string is used for the Sec-WebSocket-Accept hash calculation.
     */
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {}

    /**
     * Negotiate the handshake and return the 101 Response if successful.
     * 
     * @throws HandshakeException
     */
    public function negotiate(ServerRequestInterface $request): ResponseInterface
    {
        // 1. Validate that the request headers meet the RFC 6455 requirements
        $this->validateRequest($request);

        // 2. Extract the client-provided security key
        $key = $request->getHeaderLine('Sec-WebSocket-Key');

        // 3. Generate the Accept key by hashing the Client Key with the GUID
        // Formula: base64_encode(sha1(key + GUID, true))
        $accept = \base64_encode(\sha1($key . self::GUID, true));

        // 4. Build and return the 101 Switching Protocols response
        return $this->responseFactory->createResponse(101)
            ->withHeader('Upgrade', 'websocket')
            ->withHeader('Connection', 'Upgrade')
            ->withHeader('Sec-WebSocket-Accept', $accept);
    }

    /**
     * Validates that the request is a valid WebSocket upgrade request.
     * 
     * @throws HandshakeException
     */
    private function validateRequest(ServerRequestInterface $request): void
    {
        // WebSocket handshakes MUST be GET requests
        if ($request->getMethod() !== 'GET') {
            throw new HandshakeException('Handshake must be a GET request');
        }

        // Must have the Upgrade: websocket header
        if (\strtolower($request->getHeaderLine('Upgrade')) !== 'websocket') {
            throw new HandshakeException('Missing "Upgrade: websocket" header');
        }

        // Connection header must include "upgrade"
        if (\str_contains(\strtolower($request->getHeaderLine('Connection')), 'upgrade') === false) {
            throw new HandshakeException('Missing "Connection: Upgrade" header');
        }

        // Security key is mandatory for the challenge-response
        if (!$request->hasHeader('Sec-WebSocket-Key')) {
            throw new HandshakeException('Missing "Sec-WebSocket-Key" header');
        }

        // We only support the standard Version 13
        if ($request->getHeaderLine('Sec-WebSocket-Version') !== '13') {
            throw new HandshakeException('Only WebSocket version 13 is supported');
        }
    }
}
