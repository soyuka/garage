<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Agent\TurnContextBuilder;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\WebSearchBudget;
use App\Message\RunAgentTurn;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the garage agent for one turn, out of the HTTP request. Consumed by `messenger:consume`
 * (CLI SAPI, max_execution_time=0), so the full tool loop — persist, catalog, web_search,
 * plan_maintenance, feedback intents — has no request time limit. Render intents still reach the
 * dashboard live: {@see RenderIntentTools} publishes them to the bound session topic over Mercure
 * as the loop produces them; domain writes fan out on public vehicle topics.
 */
#[AsMessageHandler]
final readonly class RunAgentTurnHandler
{
    public function __construct(
        private AgentInterface $garage,
        private RenderIntentTools $renderTools,
        private WebSearchBudget $webSearchBudget,
        private TurnContextBuilder $contextBuilder,
    ) {
    }

    public function __invoke(RunAgentTurn $message): void
    {
        $this->renderTools->bindSession($message->sessionTopic);
        // Fresh per-turn web_search allowance — the budget service is a long-lived singleton in the
        // worker, so a prior turn's spend must not leak into this one.
        $this->webSearchBudget->reset();
        $this->renderTools->signalTurnPhase('start');

        try {
            $this->garage->call(new MessageBag(Message::ofUser($this->contextBuilder->build($message))));
        } finally {
            // Always settle the dashboard's progress heartbeat — even if the loop threw and Messenger
            // is about to retry — or the UI would stay stuck in the busy state.
            $this->renderTools->signalTurnPhase('end');
        }
    }
}
