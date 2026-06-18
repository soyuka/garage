<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\GetVehicleFactsInput;
use App\Dto\ListVehiclesInput;
use App\Dto\UpdateVehicleInput;
use App\State\GetVehicleFactsProcessor;
use App\State\ListVehiclesProcessor;
use App\State\UpdateVehicleProcessor;

/**
 * The garage: a self-strengthening vehicle store exposed as MCP tools (no HTTP operations
 * in phase 1). get_vehicle_facts reads shared garage → upstream (write-through) → miss. Facts
 * have no write tool of their own: they are recorded as `attributes` on maintenance items
 * (plan_maintenance) and read back here as a projection over those items.
 */
#[ApiResource(
    mcp: [
        'list_vehicles' => new McpTool(
            title: 'List Vehicles',
            description: 'List the vehicles held in the garage — each as an identity summary (@id, make, model, year, vehicleType, source, kType, plate, vin) plus a factCount. The @id is the vehicle\'s dereferenceable IRI (e.g. "/garage/vehicles/01J…"): reuse it as the `vehicle` argument of get_vehicle_facts/get_maintenance_history/plan_maintenance/log_maintenance/update_vehicle to address that exact row. ALWAYS call this before writing, to see what already exists and reuse a vehicle\'s @id rather than risk a duplicate. Use it to answer "what vehicles do I have?" before drilling into one with get_vehicle_facts. Filter by make and/or model and/or vehicleType. An empty result returns count 0, not an error.',
            input: ListVehiclesInput::class,
            structuredContent: false,
            processor: ListVehiclesProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        // structuredContent: false suppresses the auto-generated JSON-LD output schema
        // (which would be built from the empty Garage class and wrongly advertise @id/@type).
        // The processors return a CallToolResult directly — short-circuiting the structured
        // content processor — so they still populate structuredContent at runtime.
        'get_vehicle_facts' => new McpTool(
            title: 'Vehicle Facts',
            description: 'Get maintenance facts (oil type/capacity, coolant, brake fluid, spark plug…) for a vehicle, each tagged with provenance (source, verified) so you can caveat: "per TecDoc" vs "general knowledge, NOT manufacturer-verified". Facts are a projection over the vehicle\'s maintenance items — the specs recorded as `attributes` on the tasks you planned. Identify the vehicle by make+model OR, for one you already have a handle on (e.g. the dashboard\'s selected vehicle), by its @id (the `vehicle` argument) — then make/model are unnecessary. Known vehicles return their facts. If you pass carId (TecDoc kType) and the vehicle is in the parts catalog, its fluid specs are fetched and stored. A total miss returns a not_in_catalog result telling you to record the data as attributes on a plan_maintenance call — it is NOT an error. Filter to specific facts with keys (e.g. ["oil_capacity"]).',
            input: GetVehicleFactsInput::class,
            structuredContent: false,
            processor: GetVehicleFactsProcessor::class,
            // readOnlyHint despite the carId write-through: that is transparent catalog caching,
            // not user-intent mutation — hosts should not gate a lookup behind a confirmation.
            annotations: ['readOnlyHint' => true],
        ),
        'update_vehicle' => new McpTool(
            title: 'Update Vehicle',
            description: 'Patch a vehicle\'s OWN scalar fields: usage reading (usageValue + usageUnit km/mi/h), owner, plate, vin, year, modification. Omitted fields are left unchanged. This does NOT store maintenance facts (oil type/capacity, coolant…) — record those as `attributes` on a plan_maintenance call. Identify the vehicle by make+model (its row is created if unknown) OR, to update one you already saw in list_vehicles, by its @id (the `vehicle` argument). Dedup: if make+model has no exact match but a stored vehicle looks like the same one (same model, or same carId/plate/vin), the call returns status "ambiguous_match" with candidates instead of creating — re-call with the chosen candidate\'s @id in `vehicle`, or confirmNew:true if it is genuinely a different vehicle. usageValue and usageUnit must be provided together.',
            input: UpdateVehicleInput::class,
            structuredContent: false,
            processor: UpdateVehicleProcessor::class,
            // Patches the vehicle's own columns in place; re-sending the same values is a no-op
            // and it never deletes data, so idempotent and non-destructive.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
        ),
    ],
)]
final class Garage
{
}
