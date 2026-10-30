<?php

declare(strict_types=1);

namespace App\Agent\Journal;

use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records every executed tool call into the turn journal, in order. ToolCallsExecuted fires once per
 * tool-loop pass carrying that pass's results, so appending each as it arrives reconstructs the full
 * execution timeline — the heart of the debug journal.
 *
 * Independent of {@see \App\Agent\Ui\TerminalToolListener} (which substitutes the loop result to end
 * the turn on a terminal tool): both read the same event but neither depends on the other's ordering —
 * the tool RESULTS in the event are the original ones regardless of any substitution, so journalling
 * sees the real calls either way.
 */
#[AsEventListener(event: ToolCallsExecuted::class)]
final readonly class ToolCallJournalListener
{
    public function __construct(private TurnJournal $journal)
    {
    }

    public function __invoke(ToolCallsExecuted $event): void
    {
        foreach ($event->getToolResults() as $toolResult) {
            $call = $toolResult->getToolCall();
            $this->journal->recordToolCall($call->getName(), $call->getArguments(), $toolResult->getResult());
        }
    }
}
