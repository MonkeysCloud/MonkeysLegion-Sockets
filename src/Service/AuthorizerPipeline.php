<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Service;

use MonkeysLegion\Sockets\Contracts\ChannelAuthorizerInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;

/**
 * AuthorizerPipeline
 * 
 * Orchestrates multiple authorizers in a prioritized sequence.
 * Allows decoupling different authorization rules (e.g. Roles, IP, Database)
 * into separate, clean classes.
 */
class AuthorizerPipeline implements ChannelAuthorizerInterface
{
    /** @var array<array{authorizer: ChannelAuthorizerInterface, priority: int}> */
    private array $pipeline = [];

    /**
     * Add an authorizer to the pipeline.
     * Higher priority values are executed first.
     */
    public function addAuthorizer(ChannelAuthorizerInterface $authorizer, int $priority = 0): self
    {
        $this->pipeline[] = [
            'authorizer' => $authorizer,
            'priority' => $priority,
        ];

        // Sort by priority DESC
        usort($this->pipeline, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $this;
    }

    /**
     * Authorize a join request.
     * All authorizers in the pipeline must return true for the request to pass.
     * If any return false, the join is immediately rejected.
     */
    public function authorize(ConnectionInterface $connection, string $channel, array $parameters = []): bool
    {
        if (empty($this->pipeline)) {
            // Default to denied if a pipeline exists but is empty
            return false;
        }

        foreach ($this->pipeline as $item) {
            /** @var ChannelAuthorizerInterface $authorizer */
            $authorizer = $item['authorizer'];
            
            if (!$authorizer->authorize($connection, $channel, $parameters)) {
                return false;
            }
        }

        return true;
    }
}
