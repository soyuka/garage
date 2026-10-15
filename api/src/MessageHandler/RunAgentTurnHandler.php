<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Agent\Exception\RepeatedToolFailureException;
use App\Agent\Finder\FinderContextBuilder;
use App\Agent\Finder\FinderRenderTools;
use App\Agent\TurnSurface;
use App\Agent\Journal\TurnJournal;
use App\Agent\SelectedVehicleContext;
use App\Agent\TurnContextBuilder;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\RepeatedToolFailureGuard;
use App\Agent\Ui\StepNarrator;
use App\Agent\Ui\TurnActivityTracker;
use App\Agent\TurnCancellations;
use App\Agent\WebSearchBudget;
use App\Entity\AgentTurnStatus;
use App\Message\RunAgentTurn;
use App\I18n\AgentMessages;
use App\I18n\Locale;
use App\I18n\LocaleContext;
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
        private AgentInterface $finder,
        private FinderRenderTools $finderTools,
        private FinderContextBuilder $finderContext,
        private RenderIntentTools $renderTools,
        private WebSearchBudget $webSearchBudget,
        private RepeatedToolFailureGuard $repeatedToolFailureGuard,
        private TurnActivityTracker $activityTracker,
        private StepNarrator $stepNarrator,
        private TurnContextBuilder $contextBuilder,
        private TurnJournal $journal,
        private OwnerContext $owner,
        private LocaleContext $locale,
        private SelectedVehicleContext $selectedVehicle,
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
        // Same shape, same reason: the worker has no request, so the interface language rides the turn
        // message. Everything the agent says this turn — the heartbeat, the failure toasts, and the
        // output-language directive in the context — reads off this.
        $locale = Locale::resolve($message->locale);
        $this->locale->set($locale);
        $messages = AgentMessages::for($locale);
        // Carry the dashboard's selected vehicle so list_vehicles floats it first (singleton in the
        // worker — bound each turn, empty when nothing is selected).
        $this->selectedVehicle->set($message->selectedId);
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
        // Fresh per-turn loop-failure tally — singleton in the worker, so a prior turn's stuck calls
        // must not count against this one.
        $this->repeatedToolFailureGuard->reset();
        // Reset the per-turn activity tracker so a no-op turn (prose exit, nothing rendered/written) is
        // detected against THIS turn only.
        $this->activityTracker->reset();
        // Fresh per-turn announced-id memory — singleton in the worker, so a prior turn's tool-call
        // ids (which EarlyStepNarrationListener records early) must not suppress this turn's narration.
        $this->stepNarrator->reset();
        if (TurnSurface::Finder === $message->surface && null !== $message->vehicle) {
            $this->finderTools->bindVehicle($message->vehicle);
        }
        [$agent, $context] = match ($message->surface) {
            TurnSurface::Finder => [$this->finder, $this->finderContext->build($message)],
            TurnSurface::Garage => [$this->garage, $this->contextBuilder->build($message)],
        };
        // Open the debug journal for this turn. The context handed to the LLM is captured verbatim, so
        // the journal records exactly what the model saw (instruction + inlined facts/tasks).
        $this->journal->begin($message, $context);
        $this->renderTools->signalTurnPhase('start');

        $status = AgentTurnStatus::Completed;
        $error = null;
        try {
            $result = $agent->call(
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

            // The loop exited cleanly but the model called no write/render/terminal tool — it "answered"
            // in prose the UI cannot show. Surface it instead of leaving the dashboard silently
            // unchanged, and record it distinctly so these silent stalls are visible in the journal.
            if (!$this->activityTracker->wasProductive()) {
                $status = AgentTurnStatus::NoOp;
                $error = 'Turn ended without any write or render action (prose-only exit).';
                $this->logger->warning('Garage turn was a no-op: {error}', ['error' => $error]);
                $this->renderTools->toast(TurnSurface::Finder === $message->surface ? $messages->finderNoOp : $messages->noOp, 'warn');
            }
        } catch (MaxIterationsExceededException | RepeatedToolFailureException $e) {
            $status = AgentTurnStatus::Capped;
            $error = $e->getMessage();
            // The turn could not converge: either the blunt tool-call cap fired (AgentMaxToolCallsPass)
            // or the agent looped on the same failing call (RepeatedToolFailureGuard) — both mean a model
            // that wouldn't converge.
            // Tell the user, then dead-letter WITHOUT retrying: UnrecoverableMessageHandlerException
            // skips the retry policy (a plain rethrow would replay the same expensive runaway 3x) and
            // routes the turn straight to the `failed` transport, so capped runs stay visible for
            // monitoring instead of vanishing into a log line.
            $this->logger->warning('Garage turn did not converge, dead-lettering: {error}', ['error' => $e->getMessage()]);
            $this->renderTools->toast($messages->notConverged, 'warn');

            throw new UnrecoverableMessageHandlerException('Garage turn did not converge.', previous: $e);
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
