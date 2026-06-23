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
final readonly class GeminiWebSearchClient implements WebSearchClient
{
    private const string SYSTEM = <<<'PROMPT'
        You are a web research assistant for a vehicle-maintenance app. Use Google Search to find
        current, factual information answering the query, then reply with a concise factual answer
        grounded in what you found. Prefer manufacturer/OEM and authoritative sources. Reply in French.
        Do not ask questions and do not add caveats about being an AI — just give the grounded answer.
        PROMPT;

    public function __construct(
        private PlatformInterface $platform,
        private string $model,
        private ?WebSearchTracer $tracer = null,
    ) {
    }

    public function search(string $query): WebSearchResult
    {
        $result = $this->platform->invoke(
            $this->model,
            new MessageBag(Message::forSystem(self::SYSTEM), Message::ofUser($query)),
            ['server_tools' => ['google_search' => true]],
        );

        /** @var array<string, mixed> $data */
        $data = $result->getRawResult()->getData();

        // Trace the raw body BEFORE distilling: an empty answer (MAX_TOKENS cut, safety block, no
        // grounding) leaves no trace in the WebSearchResult, so the journal is the only place it survives.
        $this->tracer?->recordWebSearchResponse('gemini', $query, $data);

        return new WebSearchResult($this->extractAnswer($data), $this->extractCitations($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractAnswer(array $data): string
    {
        $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
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
        $seen = [];
        $citations = [];
        foreach ($data['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [] as $chunk) {
            $url = (string) ($chunk['web']['uri'] ?? '');
            if ('' === $url || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $citations[] = new Citation($url, (string) ($chunk['web']['title'] ?? ''));
        }

        return $citations;
    }
}
