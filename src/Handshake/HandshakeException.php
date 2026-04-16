<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Handshake;

use RuntimeException;

/**
 * HandshakeException
 * 
 * Exception thrown when a WebSocket handshake fails to negotiate
 * correctly with the client.
 */
class HandshakeException extends RuntimeException
{
}
