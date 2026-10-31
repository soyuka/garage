<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use App\Agent\TurnCancellations;
use App\I18n\AgentMessages;
use App\I18n\LocaleContext;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Lets the user stop a running turn from the UI. The dashboard's stop button hits the cancel
 * endpoint, which raises a {@see TurnCancellations} flag; this listener polls that flag at every
 * tool-call boundary and, when set, ends the turn the same way {@see TerminalToolListener} does —
 * by substituting a plain (empty) result so the ToolProcessor loop stops cleanly.
 *
 * Cooperative by design: the turn can only be cut between tool calls, not mid-LLM-call, so a stop
 * lands at the next round-trip — bounded, not instant. The flag is keyed by the bound session, so
 * one dashboard's stop never interrupts another session's turn.
 */
#[AsEventListener(event: ToolCallsExecuted::class)]
final readonly class CancelTurnListener
{
    public function __construct(
        private TurnCancellations $cancellations,
        private RenderIntentTools $render,
        private LocaleContext $locale,
    ) {
    }

    public function __invoke(ToolCallsExecuted $event): void
    {
        // A terminal tool (end_turn/propose_actions) may already have ended the turn this round.
        if ($event->hasResult()) {
            return;
        }

        $sessionId = $this->render->sessionId();
        if (null === $sessionId || !$this->cancellations->isRequested($sessionId)) {
            return;
        }

        // Honour the cancel once, then clear so the flag cannot bleed into a later turn on this session.
        $this->cancellations->clear($sessionId);
        $this->render->toast(AgentMessages::for($this->locale->current())->cancelled, 'warn');
        $event->setResult(new TextResult(''));
    }
}
