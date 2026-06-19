<?php

declare(strict_types=1);

namespace App\Agent;

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
    ) {
    }

    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        if ($delta instanceof TokenUsage && null !== $delta->getToolTokens()) {
            $this->budget->record($delta->getToolTokens());
        }
    }
}
