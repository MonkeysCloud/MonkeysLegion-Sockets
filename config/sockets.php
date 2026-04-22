<?php

declare(strict_types=1);

return [
    'sockets' => [
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
        | Connection Registry
        |--------------------------------------------------------------------------
        |
        | Controls how active connections and room tags are tracked.
        | Supported: "local" (Single-node), "redis" (Distributed cluster).
        |
        */
        'registry' => \getenv('WS_REGISTRY') ?: 'local',

        /*
        |--------------------------------------------------------------------------
        | Broadcast Strategy
        |--------------------------------------------------------------------------
        |
        | Controls how messages are signaled from the app to the WebSocket workers.
        | Supported: "unix" (Single-server IPC), "redis" (Distributed Pub/Sub).
        |
        */
        'broadcast' => \getenv('WS_BROADCAST') ?: 'redis',

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
        | Unix Socket Configuration
        |--------------------------------------------------------------------------
        |
        | Required when using "unix" broadcaster.
        |
        */
        'unix' => [
            'path' => \getenv('WS_UNIX_PATH') ?: '/tmp/ml_sockets.sock',
        ],

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
    ]
];
