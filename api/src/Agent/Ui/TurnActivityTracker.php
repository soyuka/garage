<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records whether a turn did anything the user can see or that persisted state. The garage agent acts
 * ONLY through tools (the generative-UI has no prose channel), so a turn that runs to a clean exit
 * having called nothing but READ tools — then "replies" in prose — is a silent no-op: nothing saved,
 * nothing rendered, the user sees the model "look at the data" and then… nothing.
 *
 * A turn is PRODUCTIVE if it called at least one write tool, one render-intent tool ({@see RenderIntentTools}), or a
 * terminal tool ({@see EndTurnTool} / propose_actions). {@see \App\MessageHandler\RunAgentTurnHandler}
 * checks {@see wasProductive()} after the loop drains and, if false, marks the turn NoOp and nudges the
 * user instead of leaving the UI silently unchanged. Complements the loop-forever guards
 * ({@see RepeatedToolFailureGuard}, the `max_tool_calls` cap) by
 * catching the opposite failure: a turn that stops too early and does nothing.
 *
 * Singleton in the consume worker, so {@see reset()} is called per turn by the handler.
 */
#[AsEventListener(event: ToolCallsExecuted::class)]
final class TurnActivityTracker
{
    /** Tools that make a turn count: a write, a render intent, or a terminal stop. */
    private const PRODUCTIVE_TOOLS = [
        'log_maintenance', 'plan_maintenance', 'edit_maintenance', 'set_maintenance_schedule', 'update_vehicle', 'dismiss_maintenance', 'remove_planned_part',
        'select_vehicle', 'open_vehicle', 'toast', 'show_fact', 'focus_view', 'reveal_assembly', 'show_document',
        'propose_actions', 'end_turn',
        'show_parts', 'show_web_part', 'ask_question',
    ];

    private bool $productive = false;

    public function reset(): void
    {
        $this->productive = false;
    }

    public function wasProductive(): bool
    {
        return $this->productive;
    }

    public function __invoke(ToolCallsExecuted $event): void
    {
        if ($this->productive) {
            return;
        }

        foreach ($event->getToolResults() as $toolResult) {
            if (\in_array($toolResult->getToolCall()->getName(), self::PRODUCTIVE_TOOLS, true)) {
                $this->productive = true;

                return;
            }
        }
    }
}
