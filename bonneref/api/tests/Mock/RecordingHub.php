<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Records every published {@see Update} instead of POSTing to a real hub, so tests can assert what
 * the agent / domain layer streamed (render intents on the session topic, domain events on the
 * public vehicle topics) without a running Mercure hub. Decorates `mercure.hub.default` in the test
 * environment; it does not delegate, so no HTTP ever leaves the test.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    public array $updates = [];

    public function getPublicUrl(): string
    {
        return 'https://example.com/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V1;
    }

    public function getCookieName(): string
    {
        return '__Secure-mercure_access_token';
    }

    public function publish(Update $update): string
    {
        $this->updates[] = $update;

        return '';
    }

    /**
     * @return list<Update>
     */
    public function updatesForTopic(string $topic): array
    {
        return array_values(array_filter($this->updates, static fn (Update $u) => \in_array($topic, $u->getTopics(), true)));
    }
}
