<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | WebSocket Driver
    |--------------------------------------------------------------------------
    |
    | This setting determines which transport driver will be used for the
    | WebSocket server. Supported: "stream", "swoole", "react".
    |
    */
    'driver' => \getenv('WS_DRIVER') ?: 'stream',

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    |
    | Listening address and port for the WebSocket server.
    |
    */
    'host' => \getenv('WS_HOST') ?: '0.0.0.0',
    'port' => (int) (\getenv('WS_PORT') ?: 8080),

    /*
    |--------------------------------------------------------------------------
    | Driver Options
    |--------------------------------------------------------------------------
    |
    | Specific options for transport drivers.
    |
    */
    'options' => [
        'max_message_size' => 10 * 1024 * 1024, // 10MB
        'write_buffer_size' => 5 * 1024 * 1024, // 5MB
        'heartbeat_interval' => 30, // Seconds
    ],
];
