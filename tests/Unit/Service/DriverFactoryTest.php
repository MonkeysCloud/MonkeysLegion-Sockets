<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Sockets\Service\DriverFactory;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Driver\SwooleDriver;
use MonkeysLegion\Sockets\Driver\ReactSocketDriver;

final class DriverFactoryTest extends TestCase
{
    #[Test]
    public function it_can_create_a_stream_driver_by_default(): void
    {
        $config = ['driver' => 'stream'];
        $factory = new DriverFactory($config);
        
        $driver = $factory->make();
        
        $this->assertInstanceOf(StreamSocketDriver::class, $driver);
    }

    #[Test]
    public function it_can_create_a_swoole_driver(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole not available');
        }

        $config = ['driver' => 'swoole'];
        $factory = new DriverFactory($config);
        
        $driver = $factory->make();
        
        $this->assertInstanceOf(SwooleDriver::class, $driver);
    }

    #[Test]
    public function it_can_create_a_react_driver(): void
    {
        $config = ['driver' => 'react'];
        $factory = new DriverFactory($config);
        
        $driver = $factory->make();
        
        $this->assertInstanceOf(ReactSocketDriver::class, $driver);
    }

    #[Test]
    public function it_throws_exception_for_unsupported_drivers(): void
    {
        $config = ['driver' => 'invalid'];
        $factory = new DriverFactory($config);
        
        $this->expectException(\InvalidArgumentException::class);
        $factory->make();
    }

    #[Test]
    public function it_casts_string_config_options_to_integers(): void
    {
        $config = [
            'driver' => 'stream',
            'options' => [
                'max_message_size' => '20971520',
                'write_buffer_size' => '10485760',
                'heartbeat_interval' => '120',
            ],
        ];
        
        $factory = new DriverFactory($config);
        $driver = $factory->make();
        $this->assertInstanceOf(StreamSocketDriver::class, $driver);

        $config['driver'] = 'react';
        $factory = new DriverFactory($config);
        $driver = $factory->make();
        $this->assertInstanceOf(ReactSocketDriver::class, $driver);
    }
}

