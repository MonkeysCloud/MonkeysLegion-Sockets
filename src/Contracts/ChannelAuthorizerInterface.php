<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Contracts;

/**
 * ChannelAuthorizerInterface
 * 
 * Defines the contract for authorizing client requests to join private channels.
 */
interface ChannelAuthorizerInterface
{
    /**
     * Authorize a join request.
     * 
     * @param ConnectionInterface $connection The connection requesting to join.
     * @param string $channel The channel name (without prefix).
     * @param array $parameters Optional parameters (e.g., token, signature).
     * @return bool True if authorized, false otherwise.
     */
    public function authorize(ConnectionInterface $connection, string $channel, array $parameters = []): bool;
}
