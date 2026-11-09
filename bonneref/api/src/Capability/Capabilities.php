<?php

declare(strict_types=1);

namespace App\Capability;

/**
 * Which {@see Capability} this deployment has a key for. An empty env var means the feature is off.
 * Read at runtime (env placeholders), so blanking a key and restarting switches it off without a
 * cache clear.
 */
final readonly class Capabilities
{
    public function __construct(
        #[\SensitiveParameter] private string $apiPlaqueToken,
    ) {
    }

    public function has(Capability $capability): bool
    {
        return match ($capability) {
            Capability::PlateLookup => '' !== trim($this->apiPlaqueToken),
        };
    }
}
