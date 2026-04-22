<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * ResponseFactory
 * 
 * A minimal, internal implementation of PSR-17 ResponseFactoryInterface.
 * Used when no external factory is provided to the negotiator.
 * 
 * @internal
 */
final class ResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new MinimalResponse($code, $reasonPhrase);
    }
}

/**
 * MinimalResponse
 * 
 * Simple PSR-7 Response stub for internal handshake use.
 * 
 * @internal
 */
final class MinimalResponse implements ResponseInterface
{
    private const array REASONS = [
        101 => 'Switching Protocols',
        200 => 'OK',
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    private array $headers = [];

    public function __construct(
        private readonly int $statusCode,
        private string $reasonPhrase = ''
    ) {
        if ($this->reasonPhrase === '' && isset(self::REASONS[$this->statusCode])) {
            $this->reasonPhrase = self::REASONS[$this->statusCode];
        }
    }

    public function getStatusCode(): int { return $this->statusCode; }

    public function withStatus(int $code, string $reasonPhrase = ''): self 
    { 
        return new self($code, $reasonPhrase); 
    }

    public function getReasonPhrase(): string { return $this->reasonPhrase; }

    public function getProtocolVersion(): string { return '1.1'; }

    public function withProtocolVersion(string $version): self { return $this; }

    public function getHeaders(): array { return $this->headers; }

    public function hasHeader(string $name): bool { return isset($this->headers[$name]); }

    public function getHeader(string $name): array { return $this->headers[$name] ?? []; }

    public function getHeaderLine(string $name): string { return \implode(', ', $this->headers[$name] ?? []); }

    public function withHeader(string $name, $value): self
    {
        $new = clone $this;
        $new->headers[$name] = (array) $value;
        return $new;
    }

    public function withAddedHeader(string $name, $value): self { return $this; }

    public function withoutHeader(string $name): self { return $this; }

    public function getBody(): \Psr\Http\Message\StreamInterface { throw new \RuntimeException('Not implemented'); }

    public function withBody(\Psr\Http\Message\StreamInterface $body): self { return $this; }
}
