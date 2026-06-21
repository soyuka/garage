<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

/**
 * Which provider grounds the out-of-band {@see WebSearchClient} side call (the `web_search` custom
 * tool's backend). Independent of the orchestrator's own platform: the garage agent can run on any
 * model and still borrow grounding from whichever backend is selected here via WEB_SEARCH_BACKEND.
 *
 *  - Anthropic: a focused Haiku call with the native `web_search` server tool ({@see AnthropicWebSearchClient}).
 *  - Gemini: a focused Gemini call with Google Search grounding ({@see GeminiWebSearchClient}).
 *
 * Distinct from {@see \App\Agent\WebSearchProvider} (the legacy per-platform input-processor mechanism,
 * kept off): that one follows the agent's platform; this one selects the side call's backend.
 */
enum WebSearchBackend: string
{
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
}
