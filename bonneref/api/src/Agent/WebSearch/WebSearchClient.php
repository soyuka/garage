<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

/**
 * Runs one web search and returns a grounded answer with its sources. This is the seam that decouples
 * the orchestrator from the search provider: the finder agent (which may be a model whose own native
 * search is weak, e.g. Qwen) calls the {@see WebSearchTool}, which delegates here. A separate, focused
 * model call does the searching and grounding, independent of whichever model orchestrates the turn.
 *
 * One implementation per backend ({@see AnthropicWebSearchClient}; a Gemini-backed one drops in by
 * implementing this interface and re-aliasing it in services.yaml — nothing else changes).
 */
interface WebSearchClient
{
    public function search(string $query): WebSearchResult;
}
