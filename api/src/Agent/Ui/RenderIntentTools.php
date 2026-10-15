<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Webmozart\Assert\Assert;

/**
 * Agent-local render-intent tools: the LLM calls these to drive the dashboard. Each validates its
 * arguments against the typed {@see UiAction} vocabulary and publishes the resulting intent to the
 * caller's *per-session* Mercure topic, so only the requesting dashboard reacts (domain writes,
 * by contrast, fan out on public vehicle topics via {@see \App\Mercure\DomainPublisher}).
 *
 * These stay `#[AsTool]` (NOT MCP): an MCP tool *call* renders nothing in a host — only a tool
 * *result* carrying a UI resource does. Host-reach therefore comes from the delivery channel, not
 * from the tool kind, which is why the vocabulary is kept transport-agnostic: today it ships over
 * the Mercure session topic; tomorrow the same intents can cross an ext-apps postMessage bridge
 * unchanged. The tools return a tiny ack string to keep tool results out of the LLM context.
 */
#[AsTool('select_vehicle', 'Focus the dashboard on one vehicle, by its @id (the IRI returned by list_vehicles, e.g. /garage/vehicles/01J...).', 'selectVehicle')]
#[AsTool('toast', 'Show a transient toast to the user. tone is one of: info, ok, warn, alarm. message is SPOKEN ALOUD by the workshop voice as well as printed — write one or two short plain spoken sentences, at most 140 characters, never a « · »/« — » label chain (full rule in the agent system prompt). Only for an answer with no follow-up card: if the turn ends with propose_actions, the answer goes in that card\'s finding instead — never toast it here too.', 'toast')]
#[AsTool('show_fact', 'Surface a focused panel showing ONLY the requested fact(s) of the selected vehicle — use it to answer a spec question like "capacité huile moteur" without dumping the whole list. TWO sources, pick ONE. (1) keys = vehicle-fact keys already STORED on the car (oil_capacity, oil_type, spark_plug_ref) — the panel filters the car\'s own sheet. (2) nodes = lines YOU fetched, which are on no fact sheet: after get_fill_volumes / get_motul_oil / lookup_plate, project the result as nodes [{key, label, value, verified, info}] with value already formatted for display ("4,3 l", "5W-30"). NEVER pass a key the vehicle does not have — keys that match no stored fact render an EMPTY panel, which is exactly the wrong answer after a successful lookup. Pass a short title for the panel header.', 'showFact')]
#[AsTool('focus_view', 'After answering, point the operator at what matters: bring ONE panel to the centre of the workshop and light the matching areas of the car. panel is "tasks" (the maintenance to-do list — use for "que dois-je faire ?"), "specs" (the spec/échéance panel — use for spec questions and manufacturer recommendations) or "carnet" (the history + spend projection — use after get_maintenance_history for "quand ai-je fait la vidange ?" / "combien j\'ai dépensé ?") or "parts" (the shopping list of every reference ALREADY chosen across the open checklist — the answer to "quelles pieces commander ?"; raise it BEFORE reaching for the catalogue, because the operator has usually already picked refs) or "docs" (the LIST of the vehicle\'s documents — manuels d\'atelier, notes techniques, devis et factures; to open ONE document at a page use show_document instead). keys are service-type or fact keys to spotlight (e.g. oil_change, timing_belt, air_filter); each lights its body region red on the car (moteur, filtration, freins…) and etches the matching panel rows. On "carnet", keys CUT the register to the visits carrying those services — pass the type you were asked about (["oil_change"] for "quand ai-je fait la vidange ?") so the answer is the top row, not 16 visits the operator must read. Then say the answer in ONE toast, as a plain spoken sentence ("La dernière vidange remonte au 6 juillet 2026.") — the panel shows the register, the toast names the datum that was asked for. Omit keys to just raise the panel.', 'focusView')]
#[AsTool('narrate_step', "Say what you are ABOUT to do, in ONE clause of at most 100 characters, first person, present tense, in the turn's language: 'Je relis le carnet…', 'Je compare les marques…'. Call it BEFORE each slow step (a catalogue call, a web search), never after it. It writes the live line on the orb, and when the operator has the audition on that line is SPOKEN — so it is where you choose the words the workshop says out loud. Omit it and a generic sentence keyed on the tool name is used instead, which says 'Je consulte le catalogue…' whether the question was about brake pads or an oil grade. It is NOT a toast: it does not outlive the step and it never carries the answer.", 'narrateStep')]
#[AsTool('reveal_assembly', 'Look INSIDE the car: ghost the shell to a wireframe and raise that region\'s schematic assembly under it, camera descending over the part. Use it when the answer is about a component the closed body hides — "quelle piece je commande ?", "ou est le filtre a carburant ?" — where a spec panel names the part but never shows where it lives. region is "hood" (the engine: oil, plugs, coolant), "bay" (around it: air/fuel filters, battery), "windshield" (cabin front: wipers, cabin filter), "frontAxle"/"rearAxle" (that axle\'s brakes and wheel). partKeys CALL OUT parts of the diagram in the operator colour (e.g. ["fuel_filter"]) — they never filter it: the whole circuit is always drawn, because what surrounds a part is how the operator finds it. It is a DIAGRAM of that kind of assembly, not a scan of this car, so never claim it depicts their specific engine. Releasing the camera ("vue libre", Escape) puts the shell back.', 'revealAssembly')]
#[AsTool('propose_actions', 'Render a call-to-action card: headline is a short title for the card (≤ 6 words) — never a sentence, never a restatement of finding. finding is an optional spoken answer (e.g. "Trouvé en ligne, une vidange et une courroie.") — two short sentences max, ≤200 characters, it is read aloud; summarise, never paste a paragraph. It is SPOKEN ALOUD instead of headline, same plain-sentence rule as toast (full text in the agent system prompt) — when a turn ends here, the answer lives in finding and must NOT also be toasted. Then a few next-step buttons. Each button is ONE of two kinds. (1) NL follow-up: set "instruction" — clicking re-runs you with that text. (2) Deterministic write: set "taskId" (an open task id from the turn context) + "parts" [{ref, brand, label}] — clicking merges those parts onto that task via a direct PATCH, NO new turn, and the shopping list re-renders. ALWAYS prefer kind (2) for "add this reference" choices, and include one button that adds the recommended refs at once. Never offer a dead-end "see all options" button.', 'proposeActions')]
final class RenderIntentTools
{
    /**
     * The car regions the client can point inside of — the single server-side copy of the vocabulary in
     * `dashboard/src/scene/anchors.ts`. A region absent here is one the agent has no word for.
     *
     * @var list<string>
     */
    public const BODY_REGIONS = ['hood', 'bay', 'windshield', 'frontAxle', 'rearAxle'];

    /** @var array{type: 'toast', tone: string, message: string}|null */
    private ?array $pendingToast = null;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        private readonly DashboardChannel $channel,
    ) {
    }

    /**
     * Binds the per-turn session topic. Called by {@see \App\MessageHandler\RunAgentTurnHandler}
     * before the agent runs; a turn is one message, so there is no cross-turn bleed. Unbound (an MCP
     * pilot call), the intents go to the owner's pilot topic — see {@see DashboardChannel}.
     */
    public function bindSession(string $sessionTopic): void
    {
        $this->channel->bindSession($sessionTopic);
    }

    /** @see DashboardChannel::sessionId() */
    public function sessionId(): ?string
    {
        return $this->channel->sessionId();
    }

    /**
     * Streams a turn-lifecycle cue to the dashboard. Not an `#[AsTool]` — the LLM never calls this;
     * {@see \App\MessageHandler\RunAgentTurnHandler} brackets the tool loop with `start`/`end` so the
     * dashboard's progress heartbeat knows the worker is running and when it settles. The HTTP 202
     * ack can no longer carry that signal now the turn runs out of the request thread.
     */
    public function signalTurnPhase(string $phase): void
    {
        Assert::inArray($phase, ['start', 'end']);

        if ('start' === $phase) {
            $this->pendingToast = null;
            $this->publish(['type' => 'turn', 'phase' => $phase]);

            return;
        }

        if (null !== $this->pendingToast) {
            $this->publish($this->pendingToast);
            $this->pendingToast = null;
        }

        $this->publish(['type' => 'turn', 'phase' => $phase]);
    }

    /**
     * Streams a transient progress sentence to the dashboard's heartbeat line. Not an `#[AsTool]` —
     * the LLM never calls this; it is emitted deterministically as the tool-call loop runs (see
     * {@see StepNarrator}, wired into {@see \App\Agent\Toolbox\CompositeToolbox}), so the user sees
     * "Je consulte le catalogue…" while the agent works, without the model spending a tool call on it.
     * A distinct `status` frame (not a `toast`) so it feeds only the heartbeat, never a toast popup.
     *
     * `$tool` rides along so the dashboard can key its step chip on the tool IDENTITY rather than on
     * the wording of the sentence: it used to keyword-match the French prose to recover exactly the
     * key we already hold here. Null for a pilot's own `narrate_step`, which has no tool to name —
     * the field is then omitted rather than sent empty.
     */
    public function narrate(string $message, ?string $tool = null): void
    {
        $this->publish(['type' => 'status', 'message' => $message, ...(null !== $tool ? ['tool' => $tool] : [])]);
    }

    /**
     * The model-authored twin of {@see narrate}: the LLM writes its own step sentence instead of
     * getting the generic one keyed on the tool it is about to call. Carries no `tool` — a line the
     * model chose has no fixed identity for the dashboard's step chip to key on.
     */
    public function narrateStep(#[Schema(maxLength: SpokenSummary::NARRATION_MAX)] string $message): string
    {
        $this->narrate(SpokenSummary::fit($message, SpokenSummary::NARRATION_MAX));

        return 'ok';
    }

    public function selectVehicle(string $id): string
    {
        $this->publish(['type' => 'selectVehicle', 'id' => $id]);

        return 'ok';
    }

    public function toast(#[Schema(maxLength: SpokenSummary::TOAST_MAX)] string $message, string $tone = 'info'): string
    {
        Assert::inArray($tone, ['info', 'ok', 'warn', 'alarm']);
        $toast = ['type' => 'toast', 'tone' => $tone, 'message' => SpokenSummary::fit($message, SpokenSummary::TOAST_MAX)];
        if (null !== $this->channel->sessionId()) {
            $this->pendingToast = $toast;
        } else {
            $this->publish($toast);
        }

        return 'ok';
    }

    /**
     * Raises the spec panel from one of two sources. `keys` FILTERS the vehicle's stored facts (none
     * ⇒ the whole sheet); `nodes` ARE the content — an already-formatted surface the tool fetched,
     * which no key over stored facts could select. Given both, the nodes win the panel and the keys
     * keep only their car-region spotlight (the client contract, `dashboard/src/focus.ts`).
     *
     * @param list<string>   $keys  vehicle-fact keys to spotlight (e.g. ["oil_capacity", "oil_type"])
     * @param list<SpecNode> $nodes fetched lines to project INSTEAD of the stored facts
     */
    public function showFact(array $keys, ?string $title = null, array $nodes = []): string
    {
        Assert::allStringNotEmpty($keys);
        Assert::allIsInstanceOf($nodes, SpecNode::class);

        $action = ['type' => 'showFact', 'keys' => array_values($keys)];
        if (null !== $title && '' !== trim($title)) {
            $action['title'] = $title;
        }
        if ([] !== $nodes) {
            $action['nodes'] = array_map(static fn (SpecNode $n): array => $n->toArray(), array_values($nodes));
        }
        $this->publish($action);

        return 'ok';
    }

    /**
     * Raises one panel into the centre slot and spotlights the given service/fact keys (each key
     * lights its body region on the car and etches the matching panel rows). The dashboard maps a
     * key to a region via its service-type table, so spotlighting is meaningful for service types
     * (oil_change → moteur, air_filter → filtration); fact-only keys just etch the row.
     *
     * @param list<string> $keys service-type or fact keys to spotlight (empty → raise the panel only)
     */
    public function focusView(string $panel, array $keys = []): string
    {
        Assert::inArray($panel, ['specs', 'tasks', 'carnet', 'parts', 'docs']);
        Assert::allStringNotEmpty($keys);
        $action = ['type' => 'focusView', 'panel' => $panel];
        if ([] !== $keys) {
            $action['keys'] = array_values($keys);
        }
        $this->publish($action);

        return 'ok';
    }

    /**
     * Opens the Atelier document viewer on one vehicle document. Not an `#[AsTool]`: the model cannot
     * know the file url, so the `show_document` processor resolves it and calls this publisher on both
     * drivers (pilot topic, or the bound turn session).
     */
    public function showDocument(string $url, string $title, string $contentType, string $filename, ?int $page = null, ?string $highlight = null, ?string $pagesUrl = null): string
    {
        Assert::stringNotEmpty($url);
        Assert::stringNotEmpty($title);
        Assert::stringNotEmpty($contentType);
        Assert::stringNotEmpty($filename);
        if (null !== $page) {
            Assert::greaterThanEq($page, 1);
        }

        $action = ['type' => 'showDocument', 'url' => $url, 'title' => $title, 'contentType' => $contentType, 'filename' => $filename];
        if (null !== $page) {
            $action['page'] = $page;
        }
        $highlight = null === $highlight ? '' : trim($highlight);
        if ('' !== $highlight) {
            $action['highlight'] = $highlight;
        }
        if (null !== $pagesUrl && '' !== $pagesUrl) {
            $action['pagesUrl'] = $pagesUrl;
        }
        $this->publish($action);

        return 'ok';
    }

    /**
     * Ghosts the car's shell to a wireframe and raises the region's schematic assembly under it — the
     * one intent that shows the operator something the closed body hides. `$partKeys` CALL OUT parts of
     * that diagram; they never filter it, because "where does this sit" is answered by what surrounds
     * it. The camera takes the region's reveal pose, and releasing the hold clears the whole thing.
     *
     * @param list<string> $partKeys service-type keys to call out (empty → raise the diagram plain)
     */
    public function revealAssembly(string $region, array $partKeys = []): string
    {
        Assert::inArray($region, self::BODY_REGIONS);
        Assert::allStringNotEmpty($partKeys);
        $action = ['type' => 'revealAssembly', 'region' => $region];
        if ([] !== $partKeys) {
            $action['partKeys'] = array_values($partKeys);
        }
        $this->publish($action);

        return 'ok';
    }

    /**
     * @param list<ActionButton> $actions next-step buttons; each is EITHER an NL follow-up
     *                                    (`instruction`, re-runs the agent) OR a deterministic write
     *                                    (`taskId` + `parts`, merges parts onto the task via PATCH)
     */
    public function proposeActions(string $headline, array $actions, #[Schema(maxLength: SpokenSummary::FINDING_MAX)] ?string $finding = null): string
    {
        Assert::notEmpty($actions);
        $buttons = array_map(static function (ActionButton $action): array {
            Assert::stringNotEmpty($action->label);

            $parts = $action->parts ?? [];
            if (null !== $action->taskId && [] !== $parts) {
                return [
                    'label' => $action->label,
                    'patchTask' => [
                        'id' => self::taskIri($action->taskId),
                        'parts' => array_map(static fn (ActionPart $p): array => $p->toArray(), $parts),
                    ],
                ];
            }

            Assert::stringNotEmpty(
                $action->instruction,
                'A button must carry either an instruction (NL follow-up) or a taskId + parts (deterministic write).',
            );

            return ['label' => $action->label, 'instruction' => $action->instruction];
        }, $actions);

        $payload = ['type' => 'proposeActions', 'headline' => $headline, 'actions' => $buttons];
        if (null !== $finding && '' !== trim($finding)) {
            $payload['finding'] = SpokenSummary::fit($finding, SpokenSummary::FINDING_MAX);
        }
        $this->pendingToast = null;
        $this->publish($payload);

        return 'ok';
    }

    /**
     * Normalise a task handle to its dereferenceable tick-off IRI. The turn context inlines open
     * tasks by bare ULID ({@see \App\Agent\TurnContextBuilder}), so the model usually passes a ULID;
     * accept an already-qualified IRI unchanged so it stays robust if the model returns the full path.
     */
    private static function taskIri(string $taskId): string
    {
        return str_contains($taskId, '/') ? $taskId : '/garage/planned-tasks/'.$taskId;
    }

    /**
     * Publishes a frame another surface's render tools built (the finder's) on the same bound session,
     * through the same best-effort path. Not an `#[AsTool]`.
     *
     * @param array<string, mixed> $frame
     */
    public function publishFrame(array $frame): void
    {
        $this->publish($frame);
    }

    /**
     * @param array<string, mixed> $action a typed UIAction
     */
    private function publish(array $action): void
    {
        $topic = $this->channel->topic();
        Assert::notNull($topic, 'No dashboard topic: neither a bound turn session nor an owner to pilot.');

        // A render intent is best-effort UI, like the domain live-updates in DomainPublisher:
        // a hub that is down (or untrusted TLS) must never turn the tool call into an error the
        // model then sees and tries to "recover" from. Log and move on — the tool still acks ok.
        try {
            $this->hub->publish(new Update($topic, json_encode($action, \JSON_THROW_ON_ERROR)));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed for render intent {type} on {topic}: {error}', [
                'type' => $action['type'] ?? '',
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
