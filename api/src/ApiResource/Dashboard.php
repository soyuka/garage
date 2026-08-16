<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\Dashboard\FocusViewInput;
use App\Dto\Dashboard\NarrateStepInput;
use App\Dto\Dashboard\OpenVehicleInput;
use App\Dto\Dashboard\ProposeActionsInput;
use App\Dto\Dashboard\RevealAssemblyInput;
use App\Dto\Dashboard\SelectVehicleInput;
use App\Dto\Dashboard\SettleDashboardInput;
use App\Dto\Dashboard\ShowFactInput;
use App\Dto\Dashboard\ToastInput;
use App\State\Dashboard\FocusViewProcessor;
use App\State\Dashboard\NarrateStepProcessor;
use App\State\Dashboard\OpenVehicleProcessor;
use App\State\Dashboard\ProposeActionsProcessor;
use App\State\Dashboard\RevealAssemblyProcessor;
use App\State\Dashboard\SelectVehicleProcessor;
use App\State\Dashboard\SettleDashboardProcessor;
use App\State\Dashboard\ShowFactProcessor;
use App\State\Dashboard\ToastProcessor;

/**
 * The dashboard as a tool surface — the PILOT channel. The garage tools above persist data; these
 * drive the workshop UI the user is looking at, so an external MCP host (Claude Code, Goose…) can
 * answer in the interface instead of in its own transcript: the user prompts in their client and
 * watches the car turn, the panel rise, the card appear.
 *
 * They are the same intents the in-app agent emits ({@see \App\Agent\Ui\RenderIntentTools}, reused
 * verbatim by the processors) — only the Mercure topic differs, since an MCP caller has no browser
 * tab and therefore no turn session. {@see \App\Agent\Ui\DashboardChannel} routes them to the
 * owner's pilot topic instead, which every dashboard of that owner subscribes to.
 *
 * They are withheld from the in-app agent ({@see \App\Mcp\Agent\McpToolbox}'s denylist): it already
 * has these as `#[AsTool]` render intents bound to the asking tab's session.
 *
 * Rendering is one-way: nothing here reads the UI back, and a frame lands only on a dashboard that
 * is already open. Narrate → act → settle, in that order, or the orb stays lit (its 90s watchdog
 * eventually settles it with a warn).
 */
#[ApiResource(
    mcp: [
        'open_vehicle' => new McpTool(
            title: 'Open Vehicle',
            description: 'START HERE when the user names a vehicle ("qu\'est-ce que j\'ai à faire sur la Mazda ?"). ONE call that resolves the spoken name (make, model, plate or VIN — case-insensitive substring), DRIVES THAT CAR onto the dashboard, and returns its whole picture: identity, the due/overdue forecast, the coverage gaps, and the open planned-maintenance checklist with its parts. It replaces the list_vehicles → select_vehicle → list_due_maintenance → list_planned_maintenance sequence: four round trips the user watches as a still screen. Never asks which car you meant — several matches resolve to the MOST-RECENTLY-TOUCHED one and the runners-up come back in `otherMatches`, so say which one you took. A name that matches nothing is a no_match result, not an error. Reach for the granular tools only when you need something this does not carry (history, facts, catalog parts).',
            input: OpenVehicleInput::class,
            structuredContent: false,
            processor: OpenVehicleProcessor::class,
            annotations: ['readOnlyHint' => true, 'idempotentHint' => true],
        ),
        'select_vehicle' => new McpTool(
            title: 'Select Vehicle',
            description: 'Focus the user\'s dashboard on ONE vehicle, by its @id (from list_vehicles, e.g. "/garage/vehicles/01J…"). The car drives forward into the workshop. Call it before show_fact / focus_view / propose_actions whenever your answer is about a vehicle other than the one on screen — those three render against the SELECTED vehicle, not the one your text names.',
            input: SelectVehicleInput::class,
            structuredContent: false,
            processor: SelectVehicleProcessor::class,
            annotations: ['readOnlyHint' => true, 'idempotentHint' => true],
        ),
        'toast' => new McpTool(
            title: 'Toast',
            description: 'Show a transient one-line acknowledgement on the dashboard, in French — what you just did or found ("Vidange enregistrée", "Aucune référence au catalogue"). tone ∈ info|ok|warn|alarm; "alarm" also docks a decision item in the à-décider inbox. This is how the user hears you: an MCP transcript they are not reading is not an answer.',
            input: ToastInput::class,
            structuredContent: false,
            processor: ToastProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'narrate_step' => new McpTool(
            title: 'Narrate Step',
            description: 'Put ONE running step sentence on the dashboard heartbeat, in French, first person present ("Je relis le carnet…", "Je cherche la référence…"). The orb lifts to work on the first one, and each call adds a step chip — so narrate before a slow lookup, not after. Close the run with settle_dashboard.',
            input: NarrateStepInput::class,
            structuredContent: false,
            processor: NarrateStepProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'settle_dashboard' => new McpTool(
            title: 'Settle Dashboard',
            description: 'End the pilot run: the orb plays its done filament and returns to veille. Pass the closing message + tone to fire the acknowledgement toast in the same call. ALWAYS call this when you are done working in the dashboard — otherwise the orb stays lit until its 90s watchdog trips.',
            input: SettleDashboardInput::class,
            structuredContent: false,
            processor: SettleDashboardProcessor::class,
            annotations: ['readOnlyHint' => true, 'idempotentHint' => true],
        ),
        'show_fact' => new McpTool(
            title: 'Show Fact',
            description: 'Surface a focused panel showing ONLY the requested fact(s) of the SELECTED vehicle — the way to answer a spec question ("capacité huile moteur") without dumping the whole list. TWO sources, pick ONE. (1) keys = vehicle-fact keys already STORED on the car (oil_capacity, oil_type, spark_plug_ref…): the panel filters the car\'s own sheet. (2) nodes = lines YOU fetched, which sit on no fact sheet: after get_fill_volumes / get_motul_oil / lookup_plate, project the result as nodes [{key, label, value, verified, info}], value already formatted for display ("4,3 l", "5W-30"). NEVER answer a lookup with keys the vehicle does not have — a key matching no stored fact renders an EMPTY panel, so the panel is emptiest exactly where you did the most work. title is the panel header.',
            input: ShowFactInput::class,
            structuredContent: false,
            processor: ShowFactProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'focus_view' => new McpTool(
            title: 'Focus View',
            description: 'Point the operator at what matters: bring ONE panel to the centre of the workshop and light the matching areas of the car. panel is "tasks" (the maintenance to-do list — use for "que dois-je faire ?"), "specs" (the spec/échéance panel) or "carnet" (past visits + spend — use after get_maintenance_history for "quand ai-je fait la vidange ?" or "combien ai-je dépensé cette année ?", instead of answering in prose) or "parts" (the shopping list of every reference ALREADY chosen across the open checklist — the answer to "quelles pieces commander ?"; raise it BEFORE reaching for the catalogue, because the operator has usually already picked refs). keys are service-type or fact keys to spotlight (oil_change, timing_belt, air_filter…); each lights its body region on the car and etches the matching panel rows. On "carnet", keys CUT the register to the visits carrying those services — pass the type you were asked about (["oil_change"] for "quand ai-je fait la vidange ?") so the answer is the top row, not every visit. Then say the answer in ONE toast ("Derniere vidange : 6 juillet 2026") — the panel shows the register, the toast names the datum that was asked for. Omit keys to just raise the panel.',
            input: FocusViewInput::class,
            structuredContent: false,
            processor: FocusViewProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'reveal_assembly' => new McpTool(
            title: 'Reveal Assembly',
            description: 'Look INSIDE the car: ghost the shell to a wireframe and raise that region\'s schematic assembly under it, the camera descending over the part. Use it when the answer is about a component the closed body hides — "quelle piece je commande ?", "ou est le filtre a carburant ?" — where show_fact names the part but never shows where it lives; pair the two (the panel carries the reference, the reveal carries the place). region is "hood" (the engine: oil, plugs, coolant), "bay" (around it: air/fuel filters, battery), "windshield" (cabin front: wipers, cabin filter), "frontAxle"/"rearAxle" (that axle\'s brakes and wheel). partKeys CALL OUT parts of the diagram in the operator colour (e.g. ["fuel_filter"]) — they never filter it: the whole circuit is always drawn, because what surrounds a part is how the operator finds it. It is a DIAGRAM of that class of assembly, not a scan of this car — never tell the user it depicts their specific engine. Releasing the camera ("vue libre", Escape) puts the shell back.',
            input: RevealAssemblyInput::class,
            structuredContent: false,
            processor: RevealAssemblyProcessor::class,
            annotations: ['readOnlyHint' => true, 'idempotentHint' => true],
        ),
        'propose_actions' => new McpTool(
            title: 'Propose Actions',
            description: 'Render a call-to-action card on the dashboard: a headline, an optional one-line finding, and a few next-step buttons. Each button is ONE of two kinds. (1) NL follow-up: set "instruction" — clicking runs the IN-APP agent with that text. (2) Deterministic write: set "taskId" (an open task id from list_planned_maintenance) + "parts" [{ref, brand, label}] — clicking merges those parts onto that task via a direct PATCH, no agent turn, and the shopping list re-renders. ALWAYS prefer kind (2) for "add this reference" choices, and include one button that adds every recommended ref at once. Use it when the user must pick a direction you cannot decide — never as a dead-end "voir toutes les options".',
            input: ProposeActionsInput::class,
            structuredContent: false,
            processor: ProposeActionsProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
    ],
)]
final class Dashboard
{
}
