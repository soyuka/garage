<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\DismissMaintenanceInput;
use App\Dto\EditMaintenanceInput;
use App\Dto\GetMaintenanceHistoryInput;
use App\Dto\ListDueMaintenanceInput;
use App\Dto\LogMaintenanceInput;
use App\Dto\PlanMaintenanceInput;
use App\Mcp\UiResourceLoader;
use App\State\DismissMaintenanceProcessor;
use App\State\EditMaintenanceProcessor;
use App\State\GetMaintenanceHistoryProcessor;
use App\State\ListDueMaintenanceProcessor;
use App\State\LogMaintenanceProcessor;
use App\State\PlanMaintenanceProcessor;

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
            description: 'Record a dated maintenance/service VISIT for a vehicle. This is an append-only LOG: every call adds one record, so a vehicle keeps its full service history — use it for "I changed the oil on <date>". ONE VISIT = ONE CALL: a vidange that changed oil AND the oil filter AND the fuel filter on the same day is a SINGLE log_maintenance call whose `items` array carries one entry per service ([{type:"oil_change",parts:[…]},{type:"oil_filter",parts:[{ref,brand}]},{type:"fuel_filter"}]) — NEVER one call per part. Each item is its own service type (so each still seeds its own due-date forecast); the parts changed go in that item\'s `parts`. The bill is visit-level: pass `cost`+`currency` once for the whole visit, plus `performedBy`, `performedAt` and the odometer/hours `usageValue`. Distinct from plan_maintenance attributes, which store a spec (e.g. oil_capacity), not an event. Identify the vehicle by make+model (its row is created if unknown) OR, to append to one you already saw in list_vehicles, by its @id (the `vehicle` argument). To avoid duplicates, if make+model has no exact match but a stored vehicle looks like the same one, the call returns status "ambiguous_match" with candidates instead of creating: re-call with the chosen candidate\'s @id in `vehicle`, or confirmNew:true if it is genuinely different. Use an item type="other" with that item\'s label for anything not in the type list (e.g. groundscare blade-sharpening). Use usageUnit="hours" for engine-hour machinery.',
            input: LogMaintenanceInput::class,
            structuredContent: false,
            processor: LogMaintenanceProcessor::class,
            // Append-only: writes (not read-only), additive only (not destructive), and every call
            // adds a fresh record (not idempotent). Hosts use these to gate confirmation prompts.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
        ),
        'edit_maintenance' => new McpTool(
            title: 'Edit Maintenance Event',
            description: 'Fix an ALREADY-logged maintenance visit in place — use it for "the oil change actually cost 149€", "set the bill on the révision complète", "that service was on the 12th not the 15th". Address the visit by its @id (the `record` argument, the IRI surfaced by get_maintenance_history). This is a SPARSE edit: pass ONLY the fields to change and everything else is left exactly as it was — its identity (@id) and any attached bills are preserved. Distinct from log_maintenance, which RECORDS a NEW visit: never edit to log a fresh service, and never log to fix an existing one (that would duplicate it). Editing usageValue corrects the record only — it does NOT move the vehicle\'s current odometer. To replace the services covered, pass a full `items` array (an empty array keeps the current items, it does not clear them). An unknown @id returns a not_found result (NOT an error).',
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
            meta: ['ui' => ['resourceUri' => UiResourceLoader::MAINTENANCE_HISTORY_URI]],
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
        'dismiss_maintenance' => new McpTool(
            title: 'Dismiss Maintenance Task',
            description: 'Remove a planned task from a vehicle\'s checklist — use it for "enlève la courroie de distribution de la liste" / "I don\'t need this one". It is a SOFT remove: the task is flipped to "dismissed", so it leaves the live checklist but is kept (recoverable, shown in the "hidden" view) — it never deletes the row. Identify the task by the same key plan_maintenance used: the vehicle (make+model OR its @id) plus the service `type` and, when the task carries one, its `label`. Distinct from ticking a task off as DONE (that credits the work via log_maintenance / the dashboard) — dismiss means the work will NOT be done. A vehicle with no matching open task returns task_not_found (NOT an error). Same ambiguous_match/confirmNew dedup as the other write tools.',
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
            description: 'Forecast which services are upcoming or overdue across the garage, soonest due first — use it for "what is the next vehicle to maintain?" (limit=1) or "what is due soon?" (withinDays). Each due item is derived from the last logged service of that type plus a time interval. The result has two honest halves: "due" (what we can predict) and "gaps" (recurring services with NO logged history, whose next-due is unknown — ask when they were last done). Due dates are time-based; for items carrying a usageInterval, ask the user for the current km/hours to refine. An empty garage returns count 0, not an error.',
            input: ListDueMaintenanceInput::class,
            structuredContent: false,
            processor: ListDueMaintenanceProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
    ],
)]
final class Maintenance
{
}
