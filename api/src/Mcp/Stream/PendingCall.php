<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

/**
 * The tool call held between the delegated response and Caddy's "ready" callback.
 *
 * The callback is a separate request with no bearer, so the owner travels here: every garage read
 * and write is owner-scoped, and resolving it again from the callback would resolve it to nobody.
 */
final readonly class PendingCall
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body,
        public array $headers,
        public ?string $owner,
    ) {
    }
}
