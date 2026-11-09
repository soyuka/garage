<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * {@see WebSearchClient} backed by a focused, one-shot Gemini call with Google Search grounding
 * (the `google_search` server tool). The Gemini ModelClient turns a `server_tools` entry into a
 * `tools: [{google_search: {}}]` body field, so passing `['google_search' => true]` enables retrieval.
 *
 * Like {@see AnthropicWebSearchClient} the call is NON-STREAM (one short grounded answer) and the raw
 * body is the only place the sources survive: the bridge's ResultConverter keeps just the text parts
 * and drops `groundingMetadata`, so we read {@see PlatformInterface::invoke()}'s raw result directly.
 *
 * Wired against the plain `ai.platform.gemini` (no decorator): the per-turn search cap is enforced by
 * {@see WebSearchTool}/{@see \App\Agent\WebSearchBudget}, not by this side call.
 */
final readonly class GeminiWebSearchClient extends AbstractWebSearchClient
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        private PlatformInterface $platform,
        private string $model,
        private ?WebSearchTracer $tracer = null,
    ) {
    }

    protected function searchToolPhrase(): string
    {
        return 'Google Search';
    }

    public function search(string $query): WebSearchResult
    {
        $result = $this->platform->invoke(
            $this->model,
            new MessageBag(Message::forSystem($this->systemPrompt()), Message::ofUser($query)),
            ['server_tools' => ['google_search' => true]],
        );

        $data = $result->getRawResult()->getData();

        // Trace the raw body BEFORE distilling: an empty answer (MAX_TOKENS cut, safety block, no
        // grounding) leaves no trace in the WebSearchResult, so the journal is the only place it survives.
        $this->tracer?->recordWebSearchResponse('gemini', $query, $data, $this->model);

        if (null !== ($unavailable = $this->errorEnvelope($data))) {
            return $unavailable;
        }

        return new WebSearchResult($this->extractAnswer($data), $this->extractCitations($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractAnswer(array $data): string
    {
        $text = '';
        foreach (self::items($data['candidates'][0]['content']['parts'] ?? null) as $part) {
            $text .= (string) ($part['text'] ?? '');
        }

        return trim($text);
    }

    /**
     * Sources the grounded answer drew on, read from `groundingMetadata.groundingChunks[].web`.
     * Deduped by URL, first occurrence wins.
     *
     * @param array<string, mixed> $data
     *
     * @return list<Citation>
     */
    private function extractCitations(array $data): array
    {
        return $this->dedupeCitations($this->sources($data));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return iterable<array{url: string, title: string}>
     */
    private function sources(array $data): iterable
    {
        foreach (self::items($data['candidates'][0]['groundingMetadata']['groundingChunks'] ?? null) as $chunk) {
            yield ['url' => (string) ($chunk['web']['uri'] ?? ''), 'title' => (string) ($chunk['web']['title'] ?? '')];
        }
    }
}
