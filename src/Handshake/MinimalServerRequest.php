<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;

/**
 * MinimalServerRequest
 * 
 * Lightweight internal implementation of ServerRequestInterface to avoid 
 * pulling in heavy PSR-7 libraries for basic handshake parsing.
 */
final class MinimalServerRequest implements ServerRequestInterface
{
    /** @var array<int|string, mixed> */
    private array $queryParams = [];

    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(
        private readonly string $method,
        private readonly string $target,
        private array $headers = [],
        private readonly string $version = '1.1'
    ) {
        $parts = \parse_url($this->target);
        if (isset($parts['query'])) {
            $queryParams = [];
            \parse_str($parts['query'], $queryParams);
            $this->queryParams = $queryParams;
        }
    }

    public function getMethod(): string { return $this->method; }
    public function getRequestTarget(): string { return $this->target; }
    public function getProtocolVersion(): string { return $this->version; }

    /**
     * @return array<int, string>
     */
    public function getHeader(string $name): array 
    { 
        $name = \strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (\strtolower($key) === $name) {
                return (array) $values;
            }
        }
        return [];
    }

    public function getHeaderLine(string $name): string 
    { 
        return \implode(', ', $this->getHeader($name)); 
    }

    public function hasHeader(string $name): bool 
    { 
        return !empty($this->getHeader($name)); 
    }

    // --- STUBS FOR UNUSED METHODS ---
    public function withProtocolVersion(string $version): static { return $this; }

    /**
     * @return array<string, array<int, string>>
     */
    public function getHeaders(): array
    {
        $normalized = [];
        foreach ($this->headers as $name => $value) {
            $normalized[$name] = (array) $value;
        }
        return $normalized;
    }

    public function withHeader(string $name, $value): static { return $this; }
    public function withAddedHeader(string $name, $value): static { return $this; }
    public function withoutHeader(string $name): static { return $this; }
    public function getBody(): StreamInterface { throw new \RuntimeException('Not implemented'); }
    public function withBody(StreamInterface $body): static { return $this; }
    public function withMethod(string $method): static { return $this; }
    public function withRequestTarget(string $requestTarget): static { return $this; }
    public function getUri(): UriInterface { throw new \RuntimeException('Not implemented'); }
    public function withUri(UriInterface $uri, bool $preserveHost = false): static { return $this; }

    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array { return []; }

    /**
     * @return array<string, mixed>
     */
    public function getCookieParams(): array { return []; }

    /**
     * @param array<string, mixed> $cookies
     */
    public function withCookieParams(array $cookies): static { return $this; }

    /**
     * @return array<int|string, mixed>
     */
    public function getQueryParams(): array { return $this->queryParams; }

    /**
     * @param array<int|string, mixed> $query
     */
    public function withQueryParams(array $query): static
    {
        $new = clone $this;
        $new->queryParams = $query;
        return $new;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUploadedFiles(): array { return []; }

    /**
     * @param array<string, mixed> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): static { return $this; }

    /**
     * @return null|array<string, mixed>|object
     */
    public function getParsedBody(): mixed { return null; }

    /**
     * @param null|array<string, mixed>|object $data
     */
    public function withParsedBody($data): static { return $this; }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array { return []; }
    public function getAttribute(string $name, $default = null): mixed { return $default; }
    public function withAttribute(string $name, $value): static { return $this; }
    public function withoutAttribute(string $name): static { return $this; }
}
