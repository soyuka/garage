<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

/**
 * Shared spine for the {@see WebSearchClient} backends. Each provider differs only in the invoke
 * options it sends and how it reads answer/citations back out of the raw body; the grounding contract
 * around that — the system prompt, the "backend error ⇒ unavailable" envelope, and URL-deduped
 * citations — is identical, so it lives here. Subclasses supply {@see searchToolPhrase()} (the one
 * clause that names their search tool) and the provider-specific extractors.
 */
abstract readonly class AbstractWebSearchClient implements WebSearchClient
{
    private const string SYSTEM_TEMPLATE = <<<'PROMPT'
        You are a web research assistant for a vehicle-maintenance app. Use %s to find
        current, factual information answering the query, then reply with a concise factual answer
        grounded in what you found. Prefer manufacturer/OEM and authoritative sources. Reply in French.
        Do not ask questions and do not add caveats about being an AI — just give the grounded answer.
        PROMPT;

    /**
     * The clause naming this backend's search tool, spliced into the shared system prompt — e.g.
     * "the web_search tool" (Anthropic) or "Google Search" (Gemini).
     */
    abstract protected function searchToolPhrase(): string;

    final protected function systemPrompt(): string
    {
        return sprintf(self::SYSTEM_TEMPLATE, $this->searchToolPhrase());
    }

    /**
     * A top-level `error` envelope (an overloaded/503 backend, a safety block) carries no content —
     * flag it unavailable rather than letting it distil to a blank answer, so the tool can tell a
     * backend failure apart from a genuinely empty result. Returns null when there is no error.
     *
     * @param array<string, mixed> $data
     */
    final protected function errorEnvelope(array $data): ?WebSearchResult
    {
        return isset($data['error']) ? new WebSearchResult('', unavailable: true) : null;
    }

    /**
     * Fold a flat source list into citations, deduped by URL (first occurrence wins), dropping empties.
     *
     * @param iterable<array{url: string, title: string}> $sources
     *
     * @return list<Citation>
     */
    final protected function dedupeCitations(iterable $sources): array
    {
        $seen = [];
        $citations = [];
        foreach ($sources as $source) {
            if ('' === $source['url'] || isset($seen[$source['url']])) {
                continue;
            }
            $seen[$source['url']] = true;
            $citations[] = new Citation($source['url'], $source['title']);
        }

        return $citations;
    }
}
