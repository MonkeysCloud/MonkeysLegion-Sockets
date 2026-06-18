<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

use MonkeysLegion\Sockets\Server\WebSocketServer;

interface SocketServerBootstrapInterface
{
    /**
     * Register connection lifecycle handlers on the driver that will call listen().
     * Invoked by socket:serve after DI is complete, before listen().
     */
    public function boot(DriverInterface $driver, WebSocketServer $server): void;
}
