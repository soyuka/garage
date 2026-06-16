<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\GetMaintenanceHistoryInput;
use App\Dto\ListDueMaintenanceInput;
use App\Dto\LogMaintenanceInput;
use App\Mcp\UiResourceLoader;
use App\State\GetMaintenanceHistoryProcessor;
use App\State\ListDueMaintenanceProcessor;
use App\State\LogMaintenanceProcessor;

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
            description: 'Record a dated maintenance/service event for a vehicle (oil change, filter swap, tyres, inspection, repair…). This is an append-only LOG: every call adds a new record, so a vehicle keeps its full service history — use it for "I changed the oil on <date>". Distinct from add_vehicle_fact, which stores a spec (e.g. oil_capacity), not an event. Identify the vehicle by make+model (its row is created if unknown) OR, to append to one you already saw in list_vehicles, by its @id (the `vehicle` argument). To avoid duplicates, if make+model has no exact match but a stored vehicle looks like the same one, the call returns status "ambiguous_match" with candidates instead of creating: re-call with the chosen candidate\'s @id in `vehicle`, or confirmNew:true if it is genuinely different. Use type="other" with a free-text label for anything not in the type list (e.g. groundscare blade-sharpening). Use usageUnit="hours" for engine-hour machinery.',
            input: LogMaintenanceInput::class,
            structuredContent: false,
            processor: LogMaintenanceProcessor::class,
            // Append-only: writes (not read-only), additive only (not destructive), and every call
            // adds a fresh record (not idempotent). Hosts use these to gate confirmation prompts.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
        ),
        'get_maintenance_history' => new McpTool(
            title: 'Maintenance History',
            description: 'Get the maintenance/service history of a vehicle, newest event first, optionally filtered by type. Use this to answer "when was the oil last changed?" or "what has been done to this vehicle?". An unknown vehicle returns a no_records result (NOT an error).',
            input: GetMaintenanceHistoryInput::class,
            structuredContent: false,
            processor: GetMaintenanceHistoryProcessor::class,
            annotations: ['readOnlyHint' => true],
            // MCP Apps (io.modelcontextprotocol/ui): links the service-history card app.
            // Tool::jsonSerialize() emits this under `_meta`, so the host reads `_meta.ui.resourceUri`.
            meta: ['ui' => ['resourceUri' => UiResourceLoader::MAINTENANCE_HISTORY_URI]],
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
