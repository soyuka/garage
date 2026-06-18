<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Per-turn cap on Anthropic's native web_search. Each loop pass is a separate API call and
 * `max_uses:1` only bounds a single call, so without a turn-level ceiling the model can search
 * once per pass — observed burning five near-identical searches on one turn, bloating the context
 * past max_tokens and starving the persist/plan steps. web_search use leaves no trace in the
 * message bag (the ResultConverter drops the block), so the count is fed in from the platform
 * decorator that reads the raw response ({@see ServerToolUseLogger}); {@see WebSearchInputProcessor}
 * withdraws the tool once spent, and {@see \App\MessageHandler\RunAgentTurnHandler} resets it per turn.
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
