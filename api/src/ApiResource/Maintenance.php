<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\DismissMaintenanceInput;
use App\Dto\EditMaintenanceInput;
use App\Dto\GetMaintenanceHistoryInput;
use App\Dto\ListDueMaintenanceInput;
use App\Dto\ListPlannedMaintenanceInput;
use App\Dto\ListServiceDefaultsInput;
use App\Dto\LogMaintenanceInput;
use App\Dto\PlanMaintenanceInput;
use App\Dto\RemovePlannedPartInput;
use App\Dto\SetMaintenanceScheduleInput;
use App\Mcp\MaintenanceHistoryApp;
use App\State\DismissMaintenanceProcessor;
use App\State\EditMaintenanceProcessor;
use App\State\GetMaintenanceHistoryProcessor;
use App\State\ListDueMaintenanceProcessor;
use App\State\ListPlannedMaintenanceProcessor;
use App\State\ListServiceDefaultsProcessor;
use App\State\LogMaintenanceProcessor;
use App\State\PlanMaintenanceProcessor;
use App\State\RemovePlannedPartProcessor;
use App\State\SetMaintenanceScheduleProcessor;

/**
 * The maintenance log: an append-only history of dated service events, exposed as MCP tools
 * (no HTTP operations in phase 1). Complements the Garage: get_vehicle_facts answers "what
 * are the specs" (oil_capacity, filter ref); these answer "what was done and when".
 */
#[ApiResource(
    mcp: [
        // structuredContent: false for the same reason as the Garage tools — the processors
        // return a CallToolResult directly with heterogeneous payloads (ok/no_records), which
        // would otherwise be reshaped into a Hydra Collection.
        'log_maintenance' => new McpTool(
            title: 'Log Maintenance Event',
            description: 'Record a dated maintenance/service VISIT for a vehicle. This is an append-only LOG: every call adds one record, so a vehicle keeps its full service history — use it for "I changed the oil on <date>". ONE VISIT = ONE CALL: a vidange that changed oil AND the oil filter AND the fuel filter on the same day is a SINGLE log_maintenance call whose `items` array carries one entry per service ([{type:"oil_change",parts:[…]},{type:"oil_filter",parts:[{ref,brand}]},{type:"fuel_filter"}]) — NEVER one call per part. Each item is its own service type (so each still seeds its own due-date forecast); the parts changed go in that item\'s `parts`. The bill is visit-level: pass `cost`+`currency` once for the whole visit, plus `performedBy`, `performedAt` and the odometer/hours `usageValue`. Distinct from set_vehicle_facts, which stores a spec (e.g. oil_capacity), not an event. Identify the vehicle by make+model (its row is created if unknown) OR, to append to one you already saw in list_vehicles, by its @id (the `vehicle` argument). To avoid duplicates, if make+model has no exact match but a stored vehicle looks like the same one, the call is refused with an ambiguous_match error listing the candidates instead of creating: re-call with the chosen candidate\'s @id in `vehicle`, or confirmNew:true if it is genuinely different. Use an item type="other" with that item\'s label for anything not in the type list (e.g. groundscare blade-sharpening). Use usageUnit="hours" for engine-hour machinery.',
            input: LogMaintenanceInput::class,
            structuredContent: false,
            processor: LogMaintenanceProcessor::class,
            // Append-only: writes (not read-only), additive only (not destructive), and every call
            // adds a fresh record (not idempotent). Hosts use these to gate confirmation prompts.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
        ),
        'edit_maintenance' => new McpTool(
            title: 'Edit Maintenance Event',
            description: 'Fix an ALREADY-logged maintenance visit in place — use it for "the oil change actually cost 149€", "set the bill on the révision complète", "that service was on the 12th not the 15th". Address the visit by its @id (the `record` argument, the IRI surfaced by get_maintenance_history). This is a SPARSE edit: pass ONLY the fields to change and everything else is left exactly as it was — its identity (@id) and any attached bills are preserved. Distinct from log_maintenance, which RECORDS a NEW visit: never edit to log a fresh service, and never log to fix an existing one (that would duplicate it). Editing usageValue corrects the record only — it does NOT move the vehicle\'s current odometer. To replace the services covered, pass a full `items` array (an empty array keeps the current items, it does not clear them). An unknown @id is a not_found error.',
            input: EditMaintenanceInput::class,
            structuredContent: false,
            processor: EditMaintenanceProcessor::class,
            // In-place edit keyed on the record @id: writes (not read-only), mutates one existing row
            // (not additive, never deletes — re-sending the same edit is a no-op), so idempotent and
            // not destructive. Hosts gate it like any write.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
        ),
        'get_maintenance_history' => new McpTool(
            title: 'Maintenance History',
            description: 'Get the maintenance/service history of a vehicle, newest event first, optionally filtered by type. Use this to answer "when was the oil last changed?" or "what has been done to this vehicle?". Identify the vehicle by make+model OR, for one you already have a handle on (e.g. the dashboard\'s selected vehicle), by its @id (the `vehicle` argument) — then make/model are unnecessary. An unknown vehicle returns a no_records result (NOT an error).',
            input: GetMaintenanceHistoryInput::class,
            structuredContent: false,
            processor: GetMaintenanceHistoryProcessor::class,
            annotations: ['readOnlyHint' => true],
            // MCP Apps (io.modelcontextprotocol/ui): links the service-history card app.
            // Tool::jsonSerialize() emits this under `_meta`, so the host reads `_meta.ui.resourceUri`.
            meta: ['ui' => ['resourceUri' => MaintenanceHistoryApp::URI]],
        ),
        'plan_maintenance' => new McpTool(
            title: 'Plan Maintenance Task',
            description: 'Add a service to a vehicle\'s checklist as an OPEN task the user intends to do — use it to answer "what should I do at 250 000 km?" or to turn a vidange into a tracked job. Each task carries the parts/fluids it needs (the shopping list, each with a part-number "ref"), an optional target it is pegged to (targetUsageValue+targetUsageUnit like "à 250 000 km", or a dueDate), and an optional free-text label. Distinct from log_maintenance, which records work ALREADY done — a planned task auto-closes once that service is logged. This is an UPSERT keyed on (vehicle, type, label): re-planning the same service on the same vehicle UPDATES the existing open task instead of duplicating it. Identify the vehicle by make+model (its row is created if unknown) OR by its @id (the `vehicle` argument); same ambiguous_match/confirmNew dedup as the other write tools.',
            input: PlanMaintenanceInput::class,
            structuredContent: false,
            processor: PlanMaintenanceProcessor::class,
            // Upsert keyed on (vehicle, type, label): re-sending the same plan updates one task, never
            // deletes — idempotent and non-destructive.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
        ),
        'remove_planned_part' => new McpTool(
            title: 'Remove Planned Part',
            description: 'Remove ONE line (a part or a consumable) from a planned task\'s shopping list — use it for "enlève le joint de la liste" / "I already have the oil filter". Pass the task\'s @id (`task`) and the line\'s `id` (`part`); both come from list_planned_maintenance (or the plan_maintenance result): task @id and parts[].id. It removes only that line and NEVER deletes the task itself — to drop a whole task from the checklist use dismiss_maintenance. Returns the updated task with its remaining parts. An unknown or foreign task/part is a not_found error.',
            input: RemovePlannedPartInput::class,
            structuredContent: false,
            processor: RemovePlannedPartProcessor::class,
            // Drops a line: writes, destructive in intent; removing an already-gone line is a no-op
            // not_found, so idempotent. Hosts should gate it behind confirmation.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
        ),
        'set_maintenance_schedule' => new McpTool(
            title: 'Set Maintenance Schedule',
            description: 'Persist a vehicle\'s RECURRING service schedule — the manufacturer/owner-manual cadences that say HOW OFTEN each service recurs, so the forecast can predict the next occurrence. Use it after looking up a maintenance schedule (e.g. "what is the maintenance schedule for my TORO 246-H?"): record EVERY cadence as its own `items` entry, never collapse them into one log. Distinct from log_maintenance (a past event) and plan_maintenance (one open to-do): this stores the rule. Each rule carries a `type`, an `action` (change/replace/clean/inspect — so ONE type holds several cadences: spark plug INSPECT every 25 h vs REPLACE every 100 h), an optional `component` (the air filter\'s "foam element" cleaned vs "paper cartridge" replaced), and at least one cadence: `everyUsageValue`+`everyUsageUnit` (use "hours" for hour-metered machinery — the axis that matters for groundscare), `everyMonths` ("or once a year" = 12; when both axes are set the soonest wins), and/or `firstUsageValue` (a break-in override, e.g. first oil change at 5 h then every 50 h). This is an UPSERT keyed on (type, action, component): re-sending a cadence refreshes it, new cadences append. Identify the vehicle by make+model (its row is created if unknown) OR by its @id; same ambiguous_match/confirmNew dedup as the other write tools.',
            input: SetMaintenanceScheduleInput::class,
            structuredContent: false,
            processor: SetMaintenanceScheduleProcessor::class,
            // Upsert keyed on (type, action, component): re-sending the same rule refreshes it, never
            // deletes — idempotent and non-destructive.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
        ),
        'dismiss_maintenance' => new McpTool(
            title: 'Dismiss Maintenance Task',
            description: 'Remove a planned task from a vehicle\'s checklist — use it for "enlève la courroie de distribution de la liste" / "I don\'t need this one". It is a SOFT remove: the task is flipped to "dismissed", so it leaves the live checklist but is kept (recoverable, shown in the "hidden" view) — it never deletes the row. Identify the task by the same key plan_maintenance used: the vehicle (make+model OR its @id) plus the service `type` and, when the task carries one, its `label`. Distinct from ticking a task off as DONE (that credits the work via log_maintenance / the dashboard) — dismiss means the work will NOT be done. A vehicle with no matching open task is a task_not_found error: nothing to remove. Same ambiguous_match/confirmNew dedup as the other write tools.',
            input: DismissMaintenanceInput::class,
            structuredContent: false,
            processor: DismissMaintenanceProcessor::class,
            // Soft remove: writes (not read-only) and re-dismissing an already-removed task is a no-op
            // task_not_found — idempotent. Destructive in intent (drops it off the checklist), though
            // the row survives, so hosts should gate it behind confirmation.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
        ),
        'list_due_maintenance' => new McpTool(
            title: 'Due Maintenance',
            description: 'Forecast which services are upcoming or overdue across the garage, soonest due first — use it for "what is the next vehicle to maintain?" (limit=1) or "what is due soon?" (withinDays). Each due item is derived from the last logged service of that type plus the vehicle\'s own recurring schedule rule (set_maintenance_schedule). There is NO general-knowledge fallback: a vehicle with no schedule produces no forecast — define its intervals first. The result has two honest halves: "due" (what we can predict) and "gaps" (scheduled services with NO logged history, whose next-due is unknown — ask when they were last done). An empty garage returns count 0, not an error.',
            input: ListDueMaintenanceInput::class,
            structuredContent: false,
            processor: ListDueMaintenanceProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'list_service_defaults' => new McpTool(
            title: 'Generic Service Baselines',
            description: 'Look up the GENERIC service interval for a maintenance type from a reviewed in-repo catalogue of baselines (timing belt/chain, spark plugs, brake fluid, coolant, oil, fuel/air/cabin filters, groundscare engine-hour cadences). CONSULT THIS BEFORE WEB-SEARCHING a generic interval, and before proposing any cadence for a vehicle that has none: it is the vetted answer, with its source and review date. Pass a vehicle @id whenever you have one — the discriminating fact is then read from that vehicle and the baselines are scoped to its segment (a mower gets "every 50 h", not a car\'s kilometres). An interval is a function of a DISCRIMINANT, not of the service: status "ambiguous" means several baselines apply and the fact that tells them apart (`discriminantKey`, e.g. distribution_type, spark_plug_material, fuel_type, coolant_type) is unknown on this vehicle — ASK THE USER or look that fact up and record it. NEVER pick one of the candidates, never average them, never invent a number. "hasInterval": false is an ANSWER, not a gap: a timing CHAIN has no replacement interval and a petrol in-tank fuel filter is a lifetime part — say so instead of hunting for a figure. PRECEDENCE: what the user stated > the vehicle\'s own stored rule (set_maintenance_schedule) or verified fact > these baselines, which never outrank a stored rule. Read-only and advisory: it writes nothing, changes no schedule and does not feed list_due_maintenance — to act on a baseline, confirm it with the user and store it with set_maintenance_schedule. Omit `type` to list the whole catalogue.',
            input: ListServiceDefaultsInput::class,
            structuredContent: false,
            processor: ListServiceDefaultsProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'list_planned_maintenance' => new McpTool(
            title: 'Planned Maintenance Checklist',
            description: 'Read a vehicle\'s OPEN planned-maintenance checklist — the live "Entretien en cours" to-do, each task WITH its parts (the shopping list, every part\'s ref/brand/label) and its spec attributes (oil grade/capacity, part references…). Use this to answer "is my current maintenance todo complete and does it have all the parts?" or "what is still to do on this vehicle?" — it returns the ACTUAL checklist the user is working through. Three honest distinctions: this is the live INTENT (what is planned but not yet done), whereas get_maintenance_history is the logged PAST (what was actually done and when) and list_due_maintenance is the FORECAST (what an interval predicts is coming, derived — not a real task). Only open, actionable tasks are returned: ones ticked off (done) or removed (dismissed) drop off, as do pure spec carriers (facts, no work attached). Identify the vehicle by make+model OR, for one you already have a handle on (e.g. the dashboard\'s selected vehicle), by its @id (the `vehicle` argument) — then make/model are unnecessary. An unknown vehicle returns a no_records result (NOT an error). Read-only: it never creates or changes anything — plan_maintenance owns adding to the checklist.',
            input: ListPlannedMaintenanceInput::class,
            structuredContent: false,
            processor: ListPlannedMaintenanceProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
    ],
)]
final class Maintenance
{
}
