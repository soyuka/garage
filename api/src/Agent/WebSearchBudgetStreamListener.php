<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Journal\TurnJournal;
use App\Agent\Ui\StepNarrator;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * Feeds {@see WebSearchBudget} from a streamed turn. On the non-stream path {@see ServerToolUseLogger}
 * counts web_search by reading the raw response; streaming has no raw body to read, so the count rides
 * the stream instead: Anthropic reports `usage.server_tool_use.web_search_requests` in each message's
 * final `message_delta`, which the Anthropic ResultConverter surfaces as a {@see TokenUsage} delta with
 * that count in `toolTokens`.
 *
 * Attached per platform call (so every pass of the agent's tool-call loop is covered, including the
 * nested recursive calls). A pass's `message_delta` arrives before its `message_stop` — i.e. before the
 * tool call that spawns the next pass — so {@see WebSearchInputProcessor} sees the spent budget in time
 * to withdraw web_search on the following pass.
 *
 * The TokenUsage delta is already consumed by the platform's TokenUsageStreamListener (it promotes it
 * to result metadata and skips it), but every listener's onDelta still fires for the delta before the
 * skip takes effect — so this listener sees it regardless of registration order.
 */
final class WebSearchBudgetStreamListener extends AbstractStreamListener
{
    public function __construct(
        private readonly WebSearchBudget $budget,
        // Signal the search step to the dashboard. web_search is server-side (no Toolbox call), so this
        // toolTokens delta — Anthropic's per-message web_search_requests count — is the only mid-turn
        // seam that sees a search ran, just as it is for the budget.
        private readonly ?StepNarrator $narrator = null,
        // The debug journal, fed from the same TokenUsage delta: the streamed path has no raw body, so
        // this is where token usage and web-search spend are observable for the journal. Optional —
        // a listener built without one simply does not journal.
        private readonly ?TurnJournal $journal = null,
    ) {
    }

    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        if (!$delta instanceof TokenUsage) {
            return;
        }

        // Token usage rides the same delta — record it on the journal regardless of any search.
        $this->journal?->recordLlmCall([], $delta->getPromptTokens(), $delta->getCompletionTokens(), $delta->getCacheReadTokens(), $delta->getCacheCreationTokens());

        $searches = $delta->getToolTokens();
        if (null === $searches || $searches < 1) {
            return;
        }

        $this->budget->record($searches);
        // Queries are not in the stream (the server_tool_use blocks are dropped on conversion); record
        // the count so the journal still shows a search ran this turn.
        $this->journal?->recordWebSearch($searches);
        $this->narrator?->forTool('web_search');
    }
}
