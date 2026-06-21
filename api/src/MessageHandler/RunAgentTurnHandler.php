<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Agent\Journal\TurnJournal;
use App\Agent\TurnContextBuilder;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\TurnCancellations;
use App\Agent\WebSearchBudget;
use App\Entity\AgentTurnStatus;
use App\Message\RunAgentTurn;
use App\Security\OwnerContext;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlerException;

/**
 * Runs the garage agent for one turn, out of the HTTP request. Consumed by `messenger:consume`
 * (CLI SAPI, max_execution_time=0), so the full tool loop — persist, catalog, web_search,
 * plan_maintenance, feedback intents — has no request time limit. Render intents still reach the
 * dashboard live: {@see RenderIntentTools} publishes them to the bound session topic over Mercure
 * as the loop produces them; domain writes fan out on public vehicle topics.
 *
 * The turn streams (`stream: true`). A non-stream Anthropic call holds the connection until the
 * whole message is built, so a long turn (web_search + Motul + plan_maintenance) overruns Symfony
 * HttpClient's idle timeout (default_socket_timeout, 60s) — the request is killed, the turn retried
 * three times and dead-lettered, and the dashboard never gets a render intent. Streaming keeps bytes
 * flowing (token deltas + pings), so the idle timer never trips regardless of turn length.
 *
 * A StreamResult is lazy: the tool loop and the render-intent Mercure publishes only run as the
 * generator is consumed, so the handler must DRAIN it. Draining also re-feeds the web_search budget
 * from the streamed TokenUsage deltas (server_tool_use.web_search_requests → toolTokens), because
 * {@see \App\Agent\ServerToolUseLogger} — which feeds the budget on the non-stream path — skips
 * streams (an eager raw read would drain them). See {@see WebSearchBudget}.
 */
#[AsMessageHandler]
final readonly class RunAgentTurnHandler
{
    public function __construct(
        private AgentInterface $garage,
        private RenderIntentTools $renderTools,
        private WebSearchBudget $webSearchBudget,
        private TurnContextBuilder $contextBuilder,
        private TurnJournal $journal,
        private OwnerContext $owner,
        private TurnCancellations $cancellations,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunAgentTurn $message): void
    {
        // Bind the tenant for this turn: the worker has no security token, so every garage write the
        // agent makes is owner-scoped via this, not via a client-supplied field. Re-bound each turn
        // because the handler is a long-lived singleton in the consume worker.
        $this->owner->set($message->owner);
        $this->renderTools->bindSession($message->sessionTopic);
        // Drop any stale cancel flag before the turn starts: a cancel that arrived after the previous
        // turn on this session already finished must not abort this fresh one. From here on, only a
        // cancel raised DURING this turn trips CancelTurnListener.
        if (null !== ($sessionId = $this->renderTools->sessionId())) {
            $this->cancellations->clear($sessionId);
        }
        // Fresh per-turn web_search allowance — the budget service is a long-lived singleton in the
        // worker, so a prior turn's spend must not leak into this one.
        $this->webSearchBudget->reset();
        // Open the debug journal for this turn. The context handed to the LLM is captured verbatim, so
        // the journal records exactly what the model saw (instruction + inlined facts/tasks).
        $context = $this->contextBuilder->build($message);
        $this->journal->begin($message, $context);
        $this->renderTools->signalTurnPhase('start');

        $status = AgentTurnStatus::Completed;
        $error = null;
        try {
            $result = $this->garage->call(
                new MessageBag(Message::ofUser($context)),
                ['stream' => true],
            );

            // Drive the lazy stream to completion: a StreamResult does nothing until consumed, and it
            // is the consumption that runs the tool-call loop and emits the render intents. The
            // web_search budget is fed by the listener ServerToolUseLogger attached to each call.
            foreach ($result->getContent() as $delta) {
                // Deltas are side-effecting (tool execution, Mercure publishes); draining is enough.
                unset($delta);
            }
        } catch (MaxIterationsExceededException $e) {
            $status = AgentTurnStatus::Capped;
            $error = $e->getMessage();
            // The tool-call cap fired (see AgentMaxToolCallsPass): a model that wouldn't converge.
            // Tell the user, then dead-letter WITHOUT retrying: UnrecoverableMessageHandlerException
            // skips the retry policy (a plain rethrow would replay the same expensive runaway 3x) and
            // routes the turn straight to the `failed` transport, so capped runs stay visible for
            // monitoring instead of vanishing into a log line.
            $this->logger->warning('Garage turn hit the tool-call cap, dead-lettering: {error}', ['error' => $e->getMessage()]);
            $this->renderTools->toast("Je n'ai pas pu terminer cette demande. Reformule ou précise ta question.", 'warn');

            throw new UnrecoverableMessageHandlerException('Garage turn exceeded the tool-call cap.', previous: $e);
        } catch (\Throwable $e) {
            // Any other failure inside the loop: record it on the journal, then let it propagate so
            // Messenger applies its retry policy unchanged.
            $status = AgentTurnStatus::Failed;
            $error = $e->getMessage();

            throw $e;
        } finally {
            // Persist the debug journal for this turn — completed, capped or failed alike, so a run
            // that misbehaved stays inspectable. Best-effort: a journal write never sinks the turn.
            $this->journal->finish($status, $error);
            // Always settle the dashboard's progress heartbeat — even if the loop threw and Messenger
            // is about to retry — or the UI would stay stuck in the busy state.
            $this->renderTools->signalTurnPhase('end');
        }
    }
}
