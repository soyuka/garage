<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\GetMaintenanceHistoryInput;
use App\Dto\LogMaintenanceInput;
use App\State\GetMaintenanceHistoryProcessor;
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
            description: 'Record a dated maintenance/service event for a vehicle (oil change, filter swap, tyres, inspection, repair…), creating the vehicle if unknown. This is an append-only LOG: every call adds a new record, so a vehicle keeps its full service history — use it for "I changed the oil on <date>". Distinct from add_vehicle_fact, which stores a spec (e.g. oil_capacity), not an event. Use type="other" with a free-text label for anything not in the type list (e.g. groundscare blade-sharpening). Use usageUnit="hours" for engine-hour machinery.',
            input: LogMaintenanceInput::class,
            structuredContent: false,
            processor: LogMaintenanceProcessor::class,
        ),
        'get_maintenance_history' => new McpTool(
            description: 'Get the maintenance/service history of a vehicle, newest event first, optionally filtered by type. Use this to answer "when was the oil last changed?" or "what has been done to this vehicle?". An unknown vehicle returns a no_records result (NOT an error).',
            input: GetMaintenanceHistoryInput::class,
            structuredContent: false,
            processor: GetMaintenanceHistoryProcessor::class,
        ),
    ],
)]
final class Maintenance
{
}
