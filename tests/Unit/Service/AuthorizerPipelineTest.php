<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use MonkeysLegion\Sockets\Service\AuthorizerPipeline;
use MonkeysLegion\Sockets\Contracts\ChannelAuthorizerInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;

#[AllowMockObjectsWithoutExpectations]
final class AuthorizerPipelineTest extends TestCase
{
    #[Test]
    public function it_denies_if_pipeline_is_empty(): void
    {
        $pipeline = new AuthorizerPipeline();
        $conn = $this->createMock(ConnectionInterface::class);
        
        $this->assertFalse($pipeline->authorize($conn, 'lobby'));
    }

    #[Test]
    public function it_approves_if_all_pass(): void
    {
        $auth1 = $this->createMock(ChannelAuthorizerInterface::class);
        $auth1->method('authorize')->willReturn(true);
        
        $auth2 = $this->createMock(ChannelAuthorizerInterface::class);
        $auth2->method('authorize')->willReturn(true);

        $pipeline = new AuthorizerPipeline();
        $pipeline->addAuthorizer($auth1)->addAuthorizer($auth2);

        $conn = $this->createMock(ConnectionInterface::class);
        $this->assertTrue($pipeline->authorize($conn, 'lobby'));
    }

    #[Test]
    public function it_denies_if_any_fails(): void
    {
        $auth1 = $this->createMock(ChannelAuthorizerInterface::class);
        $auth1->method('authorize')->willReturn(true);
        
        $auth2 = $this->createMock(ChannelAuthorizerInterface::class);
        $auth2->method('authorize')->willReturn(false);

        $pipeline = new AuthorizerPipeline();
        $pipeline->addAuthorizer($auth1)->addAuthorizer($auth2);

        $conn = $this->createMock(ConnectionInterface::class);
        $this->assertFalse($pipeline->authorize($conn, 'lobby'));
    }

    #[Test]
    public function it_respects_priority(): void
    {
        $log = [];
        
        $authLow = $this->createMock(ChannelAuthorizerInterface::class);
        $authLow->method('authorize')->willReturnCallback(function() use (&$log) {
            $log[] = 'low';
            return true;
        });

        $authHigh = $this->createMock(ChannelAuthorizerInterface::class);
        $authHigh->method('authorize')->willReturnCallback(function() use (&$log) {
            $log[] = 'high';
            return true;
        });

        $pipeline = new AuthorizerPipeline();
        $pipeline->addAuthorizer($authLow, 10);
        $pipeline->addAuthorizer($authHigh, 100);

        $conn = $this->createMock(ConnectionInterface::class);
        $pipeline->authorize($conn, 'lobby');

        $this->assertEquals(['high', 'low'], $log);
    }
}
