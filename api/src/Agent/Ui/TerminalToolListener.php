<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ends the turn the moment the agent calls a TERMINAL tool. The ToolProcessor loop continues while a
 * pass returns a ToolCallResult and exits only when the model finally returns a plain (no-tool)
 * message. A tools-only model never produces that message — the system prompt tells it to act only via
 * tools and never reply in prose, which is the very no-tool exit it forbids. Anthropic models break
 * that rule when done (they emit an `end_turn` stop on their own); Qwen and other OpenAI-compatible
 * models obey it literally and loop forever. That is the runaway that span ~26 passes and burned the
 * provider quota.
 *
 * So we make "stop" an explicit tool action, model-agnostic: when a terminal tool is among the
 * just-executed calls, {@see ToolCallsExecuted::setResult()} substitutes a plain (empty) text result,
 * so the loop ends cleanly regardless of whether the model would have stopped on its own.
 *  - `end_turn` — the explicit "I'm done" the prompt tells every turn to finish with ({@see EndTurnTool}).
 *  - `propose_actions` — also terminal: it poses a full question back to the user whose answer arrives
 *    as the NEXT turn via the CTA buttons, so there is nothing left to do this turn. The CTA was
 *    already published over Mercure by {@see RenderIntentTools::proposeActions()}.
 *
 * The empty text is never shown (the generative-UI has no prose channel). Complements the hard cap in
 * `max_tool_calls` in config/packages/ai.yaml, which is the backstop for a model
 * that ignores even this.
 */
#[AsEventListener(event: ToolCallsExecuted::class)]
final class TerminalToolListener
{
    /** A call to any of these ends the turn. */
    public const array TERMINAL_TOOLS = ['end_turn', 'propose_actions'];

    public function __invoke(ToolCallsExecuted $event): void
    {
        if ($event->hasResult()) {
            return;
        }

        foreach ($event->getToolResults() as $toolResult) {
            // A refused card (a handle outside the owner's records, a malformed call) published nothing:
            // the model must see the error and retry, not end on an empty dashboard.
            if ('propose_actions' === $toolResult->getToolCall()->getName() && 'ok' !== $toolResult->getResult()) {
                continue;
            }
            if (\in_array($toolResult->getToolCall()->getName(), self::TERMINAL_TOOLS, true)) {
                $event->setResult(new TextResult(''));

                return;
            }
        }
    }
}
