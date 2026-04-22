<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Frame\FrameProcessor;
use MonkeysLegion\Sockets\Frame\MessageAssembler;
use MonkeysLegion\Sockets\Handshake\HandshakeNegotiator;
use MonkeysLegion\Sockets\Handshake\ResponseFactory;
use MonkeysLegion\Sockets\Handshake\MiddlewarePipeline;
use MonkeysLegion\Sockets\Handshake\AllowedOriginsMiddleware;
use Psr\Log\NullLogger;

$port = (int) ($argv[1] ?? 9102);
$host = '127.0.0.1';

// Setup Handshake with CORS for tests
$pipeline = new MiddlewarePipeline();
$pipeline->add(new AllowedOriginsMiddleware(['http://localhost:3000'], new ResponseFactory()));

$negotiator = new HandshakeNegotiator(
    responseFactory: new ResponseFactory(), 
    pipeline: $pipeline
);

$driver = new StreamSocketDriver(
    frameProcessor: new FrameProcessor(),
    assembler: new MessageAssembler(),
    negotiator: $negotiator,
    logger: new NullLogger(),
    heartbeatInterval: 2
);

echo "🚀 Starting test server on $host:$port...\n";

// Minimal event setup for testing
$driver->onMessage(function($connection, $message) use ($driver) {
    $payload = $message->getPayload();
    $data = \json_decode($payload, true);
    
    if (($data['event'] ?? '') === 'hello') {
        $connection->send(\json_encode([
            'event' => 'hello',
            'payload' => ['reply' => 'Hi Monkey!']
        ]));
    }
});

$driver->listen($host, $port);
