<?php

declare(strict_types=1);

namespace App\Capability;

use App\Agent\WebSearch\WebSearchBackend;

/**
 * Which {@see Capability} this deployment has a key for. A key is the whole test: an empty env var
 * means the feature is off. Read at runtime (env placeholders), so blanking a key and restarting the
 * workers switches a feature off without a cache clear.
 */
final readonly class Capabilities
{
    public function __construct(
        #[\SensitiveParameter] private string $anthropicApiKey,
        #[\SensitiveParameter] private string $elevenLabsApiKey,
        #[\SensitiveParameter] private string $geminiApiKey,
        private WebSearchBackend $webSearchBackend,
        /** The agent's platform key ({@see AgentApiKeyPass}); null when that platform takes none. */
        #[\SensitiveParameter] private ?string $agentApiKey,
    ) {
    }

    public function has(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Agent => null === $this->agentApiKey || self::set($this->agentApiKey),
            Capability::Voice, Capability::Dictation => self::set($this->elevenLabsApiKey),
            Capability::WebSearch => self::set(match ($this->webSearchBackend) {
                WebSearchBackend::Anthropic => $this->anthropicApiKey,
                WebSearchBackend::Gemini => $this->geminiApiKey,
            }),
        };
    }

    /**
     * @return array<string, bool> keyed by {@see Capability} value, in declaration order
     */
    public function all(): array
    {
        $all = [];
        foreach (Capability::cases() as $capability) {
            $all[$capability->value] = $this->has($capability);
        }

        return $all;
    }

    private static function set(string $key): bool
    {
        return '' !== trim($key);
    }
}
