<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\GetVehicleFactsInput;
use App\Dto\ListVehiclesInput;
use App\Dto\RemoveVehicleFactsInput;
use App\Dto\SetVehicleFactsInput;
use App\Dto\UpdateVehicleInput;
use App\State\GetVehicleFactsProcessor;
use App\State\ListVehiclesProcessor;
use App\State\RemoveVehicleFactsProcessor;
use App\State\SetVehicleFactsProcessor;
use App\State\UpdateVehicleProcessor;

/**
 * The garage: a self-strengthening vehicle store exposed as MCP tools (no HTTP operations
 * in phase 1). get_vehicle_facts reads the shared garage → miss. Facts are
 * written with set_vehicle_facts / remove_vehicle_facts and read back here as a projection.
 */
#[ApiResource(
    mcp: [
        'list_vehicles' => new McpTool(
            title: 'List Vehicles',
            description: 'List the vehicles held in the garage — each as an identity summary (@id, make, model, year, vehicleType, source, kType, plate, vin) plus a factCount. Ordered MOST-RECENTLY-TOUCHED first, with the dashboard\'s currently selected vehicle floated to the top — so the FIRST result is normally the one the user is working on. PAGINATED: returns at most `limit` (default 20, max 50) from `offset`; the result carries `total` and `hasMore` for paging. The @id is the vehicle\'s dereferenceable IRI (e.g. "/garage/vehicles/01J…"): reuse it as the `vehicle` argument of get_vehicle_facts/get_maintenance_history/plan_maintenance/log_maintenance/update_vehicle to address that exact row. ALWAYS call this before writing, to see what already exists and reuse a vehicle\'s @id rather than risk a duplicate. Use it to answer "what vehicles do I have?" before drilling into one with get_vehicle_facts. Filter by make and/or model and/or vehicleType. An empty result returns count 0, not an error.',
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
            description: 'Get maintenance facts (oil type/capacity, coolant, brake fluid, spark plug…) for a vehicle, each tagged with provenance (source, verified) so you can caveat: "per TecDoc" vs "general knowledge, NOT manufacturer-verified". Facts are a projection: catalog specs, parts fitted on logged visits, and whatever was recorded with set_vehicle_facts. Identify the vehicle by make+model OR, for one you already have a handle on (e.g. the dashboard\'s selected vehicle), by its @id (the `vehicle` argument) — then make/model are unnecessary. Known vehicles return their facts. A total miss returns a not_in_catalog result pointing at set_vehicle_facts — it is NOT an error. Filter to specific facts with keys (e.g. ["oil_capacity"]).',
            input: GetVehicleFactsInput::class,
            structuredContent: false,
            processor: GetVehicleFactsProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'update_vehicle' => new McpTool(
            title: 'Update Vehicle',
            description: 'Patch a vehicle\'s OWN scalar fields: usage reading (usageValue + usageUnit km/mi/h), plate, vin, year, engineVariant (the engine/trim label, NEVER a fluid grade). Omitted fields are left unchanged. This does NOT store facts (oil type/capacity, coolant, paint code, build date, tyre size…) — record those with set_vehicle_facts. Identify the vehicle by make+model (its row is created if unknown) OR, to update one you already saw in list_vehicles, by its @id (the `vehicle` argument). Dedup: if make+model has no exact match but a stored vehicle looks like the same one (same model, or same carId/plate/vin), the call returns status "ambiguous_match" with candidates instead of creating — re-call with the chosen candidate\'s @id in `vehicle`, or confirmNew:true if it is genuinely a different vehicle. usageValue and usageUnit must be provided together.',
            input: UpdateVehicleInput::class,
            structuredContent: false,
            processor: UpdateVehicleProcessor::class,
            // Patches the vehicle's own columns in place; re-sending the same values is a no-op
            // and it never deletes data, so idempotent and non-destructive.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
        ),
        'set_vehicle_facts' => new McpTool(
            title: 'Set Vehicle Facts',
            description: 'Record facts about a vehicle: identity and spec data such as build date, paint code, tyre size, engine/gearbox code, fuel type, oil grade/capacity, coolant, part references. This is the ONLY way to store a fact — plan_maintenance does not take them. Identify the vehicle by its @id (from list_vehicles). Each fact is {key, value, unit?, source, url?}. KEYS are snake_case, subject first then aspect: oil_type, oil_capacity, coolant_type, tyre_front_size, paint_code, build_date, engine_code, gearbox_code, fuel_type, distribution_type, oil_filter_ref (a part reference is {part}_ref). Call get_vehicle_facts first and REUSE an existing key instead of inventing a synonym; writing an existing key REPLACES its value. source: "user" when the owner told you the value (it then outranks every other source), "web" with the cited url, "llm" for general knowledge, "motul" for a Motul recommendation. A fact can come back listed in `shadowed`: a part fitted on a logged bill or a value the owner set by hand still wins for that key, so do not tell the owner your value is the one on file. Returns the stored facts. Invalid input (bad key, blank value, unknown source) returns status "invalid_input" with the reason and writes nothing.',
            input: SetVehicleFactsInput::class,
            structuredContent: false,
            processor: SetVehicleFactsProcessor::class,
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
        ),
        'remove_vehicle_facts' => new McpTool(
            title: 'Remove Vehicle Facts',
            description: 'Remove facts from a vehicle by key (soft-delete: the fact leaves the card, writing the key again restores it). Identify the vehicle by its @id. Returns `removed` and `notFound` key lists. Use it to retract a wrong value, not to change one — to change a value call set_vehicle_facts, which replaces.',
            input: RemoveVehicleFactsInput::class,
            structuredContent: false,
            processor: RemoveVehicleFactsProcessor::class,
            annotations: ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
        ),
    ],
)]
final class Garage
{
}
