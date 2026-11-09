<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use App\Garage\ToolError;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;
use Webmozart\Assert\Assert;

/**
 * Agent-local render-intent tools: the LLM calls these to drive the dashboard. Each validates its
 * arguments against the typed {@see UiAction} vocabulary and publishes the resulting intent to the
 * caller's *per-session* Mercure topic, so only the requesting dashboard reacts (domain writes,
 * by contrast, go to the owner's fleet topic via {@see \App\Mercure\DomainPublisher}).
 *
 * These stay `#[AsTool]` (NOT MCP): an MCP tool *call* renders nothing in a host — only a tool
 * *result* carrying a UI resource does. Host-reach therefore comes from the delivery channel, not
 * from the tool kind, which is why the vocabulary is kept transport-agnostic: today it ships over
 * the Mercure session topic; tomorrow the same intents can cross an ext-apps postMessage bridge
 * unchanged. The tools return a tiny ack string to keep tool results out of the LLM context.
 */
#[AsTool('select_vehicle', 'Focus the dashboard on one vehicle, by its @id (the IRI returned by list_vehicles, e.g. /garage/vehicles/01J...).', 'selectVehicle')]
#[AsTool('toast', 'Show a transient toast to the user. tone is one of: info, ok, warn, alarm. message is SPOKEN ALOUD by the workshop voice as well as printed — write one or two short plain spoken sentences, at most 140 characters, never a « · »/« — » label chain (full rule in the agent system prompt). Only for an answer with no follow-up card: if the turn ends with propose_actions, the answer goes in that card\'s finding instead — never toast it here too.', 'toast')]
#[AsTool('show_fact', 'Surface a focused panel showing ONLY the requested fact(s) of the selected vehicle — use it to answer a spec question like "capacité huile moteur" without dumping the whole list. TWO sources, pick ONE. (1) keys = vehicle-fact keys already STORED on the car (oil_capacity, oil_type, spark_plug_ref) — the panel filters the car\'s own sheet. (2) nodes = lines YOU fetched, which are on no fact sheet: after get_motul_oil / lookup_plate, project the result as nodes [{key, label, value, verified, info}] with value already formatted for display ("4,3 l", "5W-30"). NEVER pass a key the vehicle does not have — keys that match no stored fact render an EMPTY panel, which is exactly the wrong answer after a successful lookup. Pass a short title for the panel header.', 'showFact')]
#[AsTool('focus_view', 'After answering, point the operator at what matters: bring ONE panel to the centre of the workshop and light the matching areas of the car. panel is "tasks" (the maintenance to-do list — use for "que dois-je faire ?"), "specs" (the spec/échéance panel — use for spec questions and manufacturer recommendations) or "carnet" (the history + spend projection — use after get_maintenance_history for "quand ai-je fait la vidange ?" / "combien j\'ai dépensé ?") or "parts" (the shopping list of every reference ALREADY chosen across the open checklist — the answer to "quelles pieces commander ?"; raise it BEFORE reaching for the catalogue, because the operator has usually already picked refs) or "docs" (the LIST of the vehicle\'s documents — manuels d\'atelier, notes techniques, devis et factures; to open ONE document at a page use show_document instead). keys are service-type or fact keys to spotlight (e.g. oil_change, timing_belt, air_filter); each lights its body region red on the car (moteur, filtration, freins…) and etches the matching panel rows. On "carnet", keys CUT the register to the visits carrying those services — pass the type you were asked about (["oil_change"] for "quand ai-je fait la vidange ?") so the answer is the top row, not 16 visits the operator must read. Then say the answer in ONE toast, as a plain spoken sentence ("La dernière vidange remonte au 6 juillet 2026.") — the panel shows the register, the toast names the datum that was asked for. Omit keys to just raise the panel.', 'focusView')]
#[AsTool('narrate_step', "Say what you are ABOUT to do, in ONE clause of at most 100 characters, first person, present tense, in the turn's language: 'Je relis le carnet…', 'Je compare les marques…'. Call it BEFORE a step worth saying (a catalogue search, a web search that will take a while), never after it, and not before every call: a quick read needs no line. It writes the live line on the orb, and when the operator has the audition on that line is SPOKEN — so it is where you choose the words the workshop says out loud. It is the ONLY sentence the ticker shows while you work: a tool you run without it shows as a silent step chip, with no generic line. It is NOT a toast: it does not outlive the step and it never carries the answer.", 'narrateStep')]
#[AsTool('reveal_assembly', 'Look INSIDE the car: ghost the shell to a wireframe and raise that region\'s schematic assembly under it, camera descending over the part. Use it when the answer is about a component the closed body hides — "quelle piece je commande ?", "ou est le filtre a carburant ?" — where a spec panel names the part but never shows where it lives. region is "hood" (the engine: oil, plugs, coolant), "bay" (around it: air/fuel filters, battery), "windshield" (cabin front: wipers, cabin filter), "frontAxle"/"rearAxle" (that axle\'s brakes and wheel). partKeys CALL OUT parts of the diagram in the operator colour (e.g. ["fuel_filter"]) — they never filter it: the whole circuit is always drawn, because what surrounds a part is how the operator finds it. It is a DIAGRAM of that kind of assembly, not a scan of this car, so never claim it depicts their specific engine. Releasing the camera ("vue libre", Escape) puts the shell back.', 'revealAssembly')]
#[AsTool('propose_actions', 'Render a call-to-action card: headline is a short title for the card (≤ 6 words) — never a sentence, never a restatement of finding. finding is an optional spoken answer (e.g. "Trouvé en ligne, une vidange et une courroie.") — two short sentences max, ≤200 characters, it is read aloud; summarise, never paste a paragraph. It is SPOKEN ALOUD instead of headline, same plain-sentence rule as toast (full text in the agent system prompt) — when a turn ends here, the answer lives in finding and must NOT also be toasted. Then a few next-step buttons. Each button is ONE of two kinds. (1) NL follow-up: set "instruction" — clicking re-runs you with that text. (2) Deterministic write: set "taskId" (an open task id from the turn context) + "parts" [{ref, brand, label}] — clicking merges those parts onto that task via a direct PATCH, NO new turn, and the shopping list re-renders. ALWAYS prefer kind (2) for "add this reference" choices, and include one button that adds the recommended refs at once. Never offer a dead-end "see all options" button.', 'proposeActions')]
final class RenderIntentTools implements ResetInterface
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
        private readonly RenderTargets $targets,
    ) {
    }

    /**
     * Binds the per-turn session topic for the length of a turn; null unbinds. The worker and the
     * terminate-mode request thread outlive the turn, so the handler unbinds in `finally` and
     * kernel.reset clears what is left. Unbound (an MCP pilot call), the intents go to the owner's
     * pilot topic — see {@see DashboardChannel}.
     */
    public function bindSession(?string $sessionTopic): void
    {
        $this->channel->bindSession($sessionTopic);
    }

    public function reset(): void
    {
        $this->pendingToast = null;
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
    public function signalTurnPhase(string $phase, bool $failed = false): void
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

        $this->publish(['type' => 'turn', 'phase' => $phase] + ($failed ? ['outcome' => 'failed'] : []));
    }

    /**
     * Streams a line the MODEL chose to say to the dashboard's heartbeat (`narrate_step`, or a pilot's
     * own narration). A distinct `status` frame (not a `toast`) so it feeds only the ticker, never a
     * toast popup. It carries no `tool`: a line the model wrote has no fixed identity for the step chip.
     */
    public function narrate(string $message): void
    {
        $this->publish(['type' => 'status', 'message' => $message]);
    }

    /**
     * Tells the dashboard a tool is running, by its key alone — the step chip, running action and orb
     * key on it. No sentence: the ticker carries only what the model chose to say. Not an `#[AsTool]`;
     * {@see StepNarrator} emits it as the tool loop runs.
     */
    public function step(string $tool): void
    {
        $this->publish(['type' => 'status', 'tool' => $tool]);
    }

    /** `narrate_step`: the model's own line for the step it is about to take. */
    public function narrateStep(#[Schema(maxLength: SpokenSummary::NARRATION_MAX)] string $message): string
    {
        $this->narrate(SpokenSummary::fit($message, SpokenSummary::NARRATION_MAX));

        return 'ok';
    }

    public function selectVehicle(string $id): string
    {
        return self::ack($this->trySelectVehicle($id));
    }

    /** `select_vehicle` for the MCP driver: the refusal as a tool error, or null once published. */
    public function trySelectVehicle(string $id): ?ToolError
    {
        $iri = $this->targets->vehicle($id);
        if ($iri instanceof ToolError) {
            return $iri;
        }
        $this->publish(['type' => 'selectVehicle', 'id' => $iri]);

        return null;
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

        // @phpstan-ignore arrayValues.list (model-supplied tool arguments: the list shape is the advertised schema, not a runtime guarantee)
        $action = ['type' => 'showFact', 'keys' => array_values($keys)];
        if (null !== $title && '' !== trim($title)) {
            $action['title'] = $title;
        }
        if ([] !== $nodes) {
            // @phpstan-ignore arrayValues.list (model-supplied tool arguments: the list shape is the advertised schema, not a runtime guarantee)
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
            // @phpstan-ignore arrayValues.list (model-supplied tool arguments: the list shape is the advertised schema, not a runtime guarantee)
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
     * Plays the documentation scan in the Atelier: the viewer steps through the pages a
     * `search_documentation` call matched, best first, until `show_document` settles it. Not an
     * `#[AsTool]`: the search processor publishes it on both drivers. `$hits` empty = no match.
     *
     * @param list<array{documentId: string, page: int, pageLabel: ?string, url: string, title: string, contentType: string, filename: string, pagesUrl: string}> $hits
     */
    public function documentScan(string $vehicle, string $query, array $hits): void
    {
        Assert::stringNotEmpty($vehicle);
        Assert::stringNotEmpty($query);
        $this->publish(['type' => 'documentScan', 'vehicle' => $vehicle, 'query' => $query, 'hits' => $hits]);
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
            // @phpstan-ignore arrayValues.list (model-supplied tool arguments: the list shape is the advertised schema, not a runtime guarantee)
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
        return self::ack($this->tryProposeActions($headline, $actions, $finding));
    }

    /**
     * `propose_actions` for the MCP driver: the refusal as a tool error, or null once published. A
     * write button's task must be one of the owner's open tasks — the dashboard PATCHes it with the
     * operator's bearer.
     *
     * @param list<ActionButton> $actions
     */
    public function tryProposeActions(string $headline, array $actions, ?string $finding = null): ?ToolError
    {
        Assert::notEmpty($actions);
        $buttons = [];
        foreach ($actions as $action) {
            Assert::stringNotEmpty($action->label);

            $parts = $action->parts ?? [];
            if (null !== $action->taskId && [] !== $parts) {
                $task = $this->targets->task($action->taskId);
                if ($task instanceof ToolError) {
                    return $task;
                }
                $buttons[] = [
                    'label' => $action->label,
                    'patchTask' => [
                        'id' => $task,
                        'parts' => array_map(static fn (ActionPart $p): array => $p->toArray(), $parts),
                    ],
                ];

                continue;
            }

            Assert::stringNotEmpty(
                $action->instruction,
                'A button must carry either an instruction (NL follow-up) or a taskId + parts (deterministic write).',
            );

            $buttons[] = ['label' => $action->label, 'instruction' => $action->instruction];
        }

        $payload = ['type' => 'proposeActions', 'headline' => $headline, 'actions' => $buttons];
        if (null !== $finding && '' !== trim($finding)) {
            $payload['finding'] = SpokenSummary::fit($finding, SpokenSummary::FINDING_MAX);
        }
        $this->pendingToast = null;
        $this->publish($payload);

        return null;
    }

    /** The agent tool's answer: `ok`, or the tool error's text so the model can correct the handle. */
    private static function ack(?ToolError $error): string
    {
        return null === $error ? 'ok' : $error->status.': '.$error->reason;
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
