<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The explicit "the turn is finished" signal. The tool-call loop exits only when the model returns a
 * message with no tool call; a tools-only model never does that (the prompt tells it to act only via
 * tools, never in prose), so it would loop forever. This tool makes stopping itself a tool action:
 * the model calls end_turn as its last step and {@see TerminalToolListener} ends the loop. Returns a
 * bare ack — the value is irrelevant, the loop is already over by the time it would be read.
 *
 * Not a render intent (it drives nothing on the dashboard) — it is pure loop control — but it lives
 * among the agent's #[AsTool]s so `tools: true` picks it up into the garage toolbox automatically.
 * The attribute is CLASS-level (like {@see RenderIntentTools}): the bundle's autoconfiguration only
 * tags class-level #[AsTool], not method-level.
 */
#[AsTool('end_turn', 'Call this as the LAST tool of the turn, once you have finished acting (after the closing toast and select_vehicle), to end the turn. Takes no arguments.', 'endTurn')]
final class EndTurnTool
{
    public function endTurn(): string
    {
        return 'ok';
    }
}
