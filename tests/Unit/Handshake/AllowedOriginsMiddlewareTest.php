<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Handshake;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\Sockets\Handshake\AllowedOriginsMiddleware;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use Psr\Http\Message\ServerRequestInterface;
use PHPUnit\Framework\Attributes\DataProvider;

class AllowedOriginsMiddlewareTest extends TestCase
{
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->responseFactory = new ResponseFactory();
    }

    #[DataProvider('originProvider')]
    public function test_origin_matching(array $allowed, string $origin, int $expectedStatus): void
    {
        $middleware = new AllowedOriginsMiddleware($allowed, $this->responseFactory);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturn($origin);

        $next = fn($req) => $this->responseFactory->createResponse(101);

        $response = $middleware->handle($request, $next);

        $this->assertEquals($expectedStatus, $response->getStatusCode());
    }

    public static function originProvider(): array
    {
        return [
            'wildcard_http_protocol' => [['http://*.example.com'], 'http://app.example.com', 101],
            'wildcard_multiple_levels_denied' => [['http://*.example.com'], 'http://sub.app.example.com', 403], 
            'exact_match' => [['https://app.monkeys.com'], 'https://app.monkeys.com', 101],
            'case_insensitive_match' => [['https://APP.monkeys.com'], 'https://app.MONKEYS.com', 101],
            'trailing_slash_in_allowed' => [['https://example.com/'], 'https://example.com', 101],
            'trailing_slash_in_origin' => [['https://example.com'], 'https://example.com/', 101],
            'regex_characters_in_allowed' => [['http://a+b.com'], 'http://a+b.com', 101],
            'regex_characters_not_treated_as_regex' => [['http://a+b.com'], 'http://aab.com', 403],
            'empty_origin_allowed_by_default' => [['http://example.com'], '', 101],
            'global_wildcard' => [['*'], 'https://evil.com', 101],
            'unmatched_origin' => [['http://localhost:3000'], 'http://localhost:8000', 403],
        ];
    }
}
