<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

/**
 * Which provider grounds the out-of-band {@see WebSearchClient} side call (the `web_search` custom
 * tool's backend). Independent of the orchestrator's own platform: the finder agent can run on any
 * model and still borrow grounding from whichever backend is selected here via WEB_SEARCH_BACKEND.
 *
 *  - Anthropic: a focused Haiku call with the native `web_search` server tool ({@see AnthropicWebSearchClient}).
 *  - Gemini: a focused Gemini call with Google Search grounding ({@see GeminiWebSearchClient}).
 */
enum WebSearchBackend: string
{
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
}
