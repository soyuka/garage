<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ends the turn once the agent renders a call-to-action. `propose_actions` IS the terminal move: it
 * poses a full question back to the user, whose answer arrives as the NEXT turn via the CTA buttons —
 * so there is nothing left for the model to do this turn. Without this, the render-intent tools are
 * non-terminal (each returns an "ok" ack), so a model that keeps calling them never stops; that is
 * the runaway that looped ~26 times and burned the provider quota.
 *
 * Mechanism: the ToolProcessor loop continues while the post-tool result is a ToolCallResult and
 * exits the moment it is anything else. {@see ToolCallsExecuted} lets a listener substitute that
 * result — so when `propose_actions` is among the just-executed calls, we set a plain (empty) text
 * result and the loop ends cleanly. The CTA itself was already published to the dashboard over
 * Mercure by {@see RenderIntentTools::proposeActions()}; the empty text is never shown (the
 * generative-UI has no prose channel). Complements the hard cap in
 * {@see \App\DependencyInjection\Compiler\AgentMaxToolCallsPass}.
 */
#[AsEventListener(event: ToolCallsExecuted::class)]
final class TerminalProposeActionsListener
{
    public function __invoke(ToolCallsExecuted $event): void
    {
        if ($event->hasResult()) {
            return;
        }

        foreach ($event->getToolResults() as $toolResult) {
            if ('propose_actions' === $toolResult->getToolCall()->getName()) {
                $event->setResult(new TextResult(''));

                return;
            }
        }
    }
}
