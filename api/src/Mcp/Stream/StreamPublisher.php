<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes a delegated answer onto its topic, as the SSE events the MCP client expects.
 *
 * Mercure's `type` becomes the SSE `event:` line, so an MCP `event: message` frame survives the hop
 * unchanged. The terminal event is what tells Caddy to close the stream; without it the client waits
 * until the configured timeout.
 */
final readonly class StreamPublisher
{
    public function __construct(
        private HubInterface $hub,
        /** Must match the Caddyfile's `end_event` (its default is this value). */
        private string $endEvent = 'mercure-stream-end',
    ) {
    }

    public function message(string $topic, string $json): void
    {
        $this->hub->publish(new Update($topic, $json, private: true, type: 'message'));
    }

    public function end(string $topic): void
    {
        $this->hub->publish(new Update($topic, '', private: true, type: $this->endEvent));
    }
}
