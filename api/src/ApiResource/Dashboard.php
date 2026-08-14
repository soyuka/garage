<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\Dashboard\FocusViewInput;
use App\Dto\Dashboard\NarrateStepInput;
use App\Dto\Dashboard\ProposeActionsInput;
use App\Dto\Dashboard\SelectVehicleInput;
use App\Dto\Dashboard\SettleDashboardInput;
use App\Dto\Dashboard\ShowFactInput;
use App\Dto\Dashboard\ToastInput;
use App\State\Dashboard\FocusViewProcessor;
use App\State\Dashboard\NarrateStepProcessor;
use App\State\Dashboard\ProposeActionsProcessor;
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
            description: 'Surface a focused panel showing ONLY the requested fact(s) of the SELECTED vehicle — the way to answer a spec question ("capacité huile moteur") without dumping the whole list. keys are vehicle-fact keys (oil_capacity, oil_type, spark_plug_ref…); title is the panel header.',
            input: ShowFactInput::class,
            structuredContent: false,
            processor: ShowFactProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'focus_view' => new McpTool(
            title: 'Focus View',
            description: 'Point the operator at what matters: bring ONE panel to the centre of the workshop and light the matching areas of the car. panel is "tasks" (the maintenance to-do list — use for "que dois-je faire ?") or "specs" (the spec/échéance panel). keys are service-type or fact keys to spotlight (oil_change, timing_belt, air_filter…); each lights its body region on the car and etches the matching panel rows. Omit keys to just raise the panel.',
            input: FocusViewInput::class,
            structuredContent: false,
            processor: FocusViewProcessor::class,
            annotations: ['readOnlyHint' => true],
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
