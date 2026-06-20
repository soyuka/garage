<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Which provider's web-search mechanism the garage agent declares per turn. The two providers expose
 * web search through incompatible request shapes, so the mechanism must follow the platform the agent
 * is wired to (see config/packages/ai.yaml + docs/AGENT_PROVIDER.md):
 *  - Anthropic: a server-side tool sent as a `server_tools` block.
 *  - Qwen (DashScope OpenAI-compatible): an `enable_search` boolean body field.
 * Off withholds web search entirely (e.g. a provider with no supported mechanism).
 */
enum WebSearchProvider: string
{
    case Anthropic = 'anthropic';
    case Qwen = 'qwen';
    case Off = 'off';
}
