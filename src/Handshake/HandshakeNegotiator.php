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
        $this->validateRequest($request);

        $key = $request->getHeaderLine('Sec-WebSocket-Key');
        $accept = \base64_encode(\sha1($key . self::GUID, true));

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
        if ($request->getMethod() !== 'GET') {
            throw new HandshakeException('Handshake must be a GET request');
        }

        if (\strtolower($request->getHeaderLine('Upgrade')) !== 'websocket') {
            throw new HandshakeException('Missing "Upgrade: websocket" header');
        }

        if (\str_contains(\strtolower($request->getHeaderLine('Connection')), 'upgrade') === false) {
            throw new HandshakeException('Missing "Connection: Upgrade" header');
        }

        if (!$request->hasHeader('Sec-WebSocket-Key')) {
            throw new HandshakeException('Missing "Sec-WebSocket-Key" header');
        }

        if ($request->getHeaderLine('Sec-WebSocket-Version') !== '13') {
            throw new HandshakeException('Only WebSocket version 13 is supported');
        }
    }
}
