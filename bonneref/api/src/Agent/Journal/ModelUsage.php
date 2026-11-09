<?php

declare(strict_types=1);

namespace App\Agent\Journal;

/**
 * The billable usage of one model call, read off the provider's raw response body.
 */
final readonly class ModelUsage
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheCreationTokens = 0,
        public int $webSearchRequests = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $body a Messages API response
     */
    public static function fromAnthropic(array $body): self
    {
        $usage = \is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $serverTools = \is_array($usage['server_tool_use'] ?? null) ? $usage['server_tool_use'] : [];

        return new self(
            promptTokens: self::int($usage['input_tokens'] ?? null),
            completionTokens: self::int($usage['output_tokens'] ?? null),
            cacheReadTokens: self::int($usage['cache_read_input_tokens'] ?? null),
            cacheCreationTokens: self::int($usage['cache_creation_input_tokens'] ?? null),
            webSearchRequests: self::int($serverTools['web_search_requests'] ?? null),
        );
    }

    /**
     * Thinking tokens are billed as output.
     *
     * @param array<string, mixed> $body a generateContent response
     */
    public static function fromGemini(array $body): self
    {
        $usage = \is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];

        return new self(
            promptTokens: self::int($usage['promptTokenCount'] ?? null),
            completionTokens: self::int($usage['candidatesTokenCount'] ?? null) + self::int($usage['thoughtsTokenCount'] ?? null),
            cacheReadTokens: self::int($usage['cachedContentTokenCount'] ?? null),
        );
    }

    /**
     * @return array{promptTokens: int, completionTokens: int, cacheReadTokens: int, cacheCreationTokens: int}
     */
    public function tokens(): array
    {
        return [
            'promptTokens' => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'cacheReadTokens' => $this->cacheReadTokens,
            'cacheCreationTokens' => $this->cacheCreationTokens,
        ];
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) && $value > 0 ? $value : 0;
    }
}
