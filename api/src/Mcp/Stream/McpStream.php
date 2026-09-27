<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

/**
 * One delegated MCP answer: the topic Caddy subscribes to and the token it subscribes with.
 */
final readonly class McpStream
{
    public function __construct(
        public string $id,
        public string $topic,
        public string $subscriberToken,
    ) {
    }
}
