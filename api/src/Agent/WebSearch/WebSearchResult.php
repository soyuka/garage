<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

/**
 * The grounded outcome of one {@see WebSearchClient::search()} call: the model's synthesised answer
 * plus the sources it cited. Provider-agnostic — an Anthropic-backed or (later) Gemini-backed client
 * produces the same shape, so {@see WebSearchTool} formats either identically for the orchestrator.
 */
final readonly class WebSearchResult
{
    /**
     * @param list<Citation> $citations
     */
    public function __construct(
        public string $answer,
        public array $citations = [],
    ) {
    }
}
