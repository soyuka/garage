<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

/**
 * The grounded outcome of one {@see WebSearchClient::search()} call: the model's synthesised answer
 * plus the sources it cited. Provider-agnostic — an Anthropic-backed or (later) Gemini-backed client
 * produces the same shape, so {@see WebSearchTool} formats either identically for the orchestrator.
 *
 * `unavailable` distinguishes a BACKEND failure (the search provider was overloaded/errored, e.g. a 503)
 * from a genuine empty answer. Both yield an empty {@see $answer}, but the orchestrator must treat them
 * differently — "the service is down, pivot" vs "the web has nothing" — so the client flags it here and
 * {@see WebSearchTool} returns a distinct message.
 */
final readonly class WebSearchResult
{
    /**
     * @param list<Citation> $citations
     */
    public function __construct(
        public string $answer,
        public array $citations = [],
        public bool $unavailable = false,
    ) {
    }
}
