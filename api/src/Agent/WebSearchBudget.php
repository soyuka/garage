<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Per-turn cap on web search — the costliest move (each cited result is re-sent on every subsequent
 * loop pass, bloating context past max_tokens and starving the persist/plan steps). Reset per turn by
 * {@see \App\MessageHandler\RunAgentTurnHandler}.
 *
 * Two feeders, depending on the active web-search path:
 *  - Custom-tool path (default): {@see \App\Agent\WebSearch\WebSearchTool} records one use per call and
 *    refuses once exhausted — a hard ceiling on how often the orchestrator may search per turn.
 *  - Legacy native path ({@see WebSearchInputProcessor}, AGENT_WEB_SEARCH=anthropic): web_search use
 *    leaves no trace in the message bag (the ResultConverter drops the block), so the count is fed from
 *    the platform decorator that reads the raw response ({@see ServerToolUseLogger}), and the processor
 *    withdraws the tool once spent.
 */
final class WebSearchBudget
{
    private int $used = 0;

    public function __construct(
        private readonly int $maxPerTurn = 1,
    ) {
    }

    public function reset(): void
    {
        $this->used = 0;
    }

    public function record(int $searches): void
    {
        if ($searches > 0) {
            $this->used += $searches;
        }
    }

    public function exhausted(): bool
    {
        return $this->used >= $this->maxPerTurn;
    }
}
