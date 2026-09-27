<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Uid\Ulid;

/**
 * Opens a one-shot stream for a delegated tool call.
 *
 * The token grants subscribe on this topic alone. That matters because the hub takes anonymous
 * subscribers (frankenphp/Caddyfile), so without a scoped grant the topic string would be the only
 * thing separating one caller's answer from another's — the same reasoning as the pilot topic in
 * {@see \App\Agent\Ui\DashboardChannel}, but here we can do better than an unguessable name.
 */
final readonly class McpStreamFactory
{
    public function __construct(
        private HubInterface $hub,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $baseUri,
    ) {
    }

    public function open(): McpStream
    {
        $id = (new Ulid())->toRfc4122();
        $topic = rtrim($this->baseUri, '/').'/mcp/streams/'.$id;

        $factory = $this->hub->getFactory()
            ?? throw new \LogicException('The Mercure hub has no token factory, so no subscriber token can be minted for a delegated MCP stream.');

        return new McpStream($id, $topic, $factory->create([new Grant([Grant::ACTION_SUBSCRIBE], [$topic])]));
    }
}
