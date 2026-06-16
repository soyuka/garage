<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\AddVehicleFactInput;
use App\Dto\GetVehicleFactsInput;
use App\Dto\ListVehiclesInput;
use App\State\AddVehicleFactProcessor;
use App\State\GetVehicleFactsProcessor;
use App\State\ListVehiclesProcessor;

/**
 * The garage: a self-strengthening vehicle store exposed as MCP tools (no HTTP operations
 * in phase 1). get_vehicle_facts reads shared garage → upstream (write-through) → miss;
 * add_vehicle_fact lets the LLM fill catalog misses, with provenance and a precedence rule
 * that never lets an llm/user value overwrite a verified authoritative fact.
 */
#[ApiResource(
    mcp: [
        'list_vehicles' => new McpTool(
            title: 'List Vehicles',
            description: 'List the vehicles held in the garage — each as an identity summary (id, make, model, year, vehicleType, source, kType, plate, vin) plus a factCount. ALWAYS call this before writing, to see what already exists and reuse a vehicle\'s id rather than risk a duplicate. Use it to answer "what vehicles do I have?" before drilling into one with get_vehicle_facts. Filter by make and/or model and/or vehicleType. An empty result returns count 0, not an error.',
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
            description: 'Get maintenance facts (oil type/capacity, coolant, brake fluid, spark plug…) for a vehicle, each tagged with provenance (source, verified) so you can caveat: "per TecDoc" vs "general knowledge, NOT manufacturer-verified". Known vehicles return their stored facts. If you pass carId (TecDoc kType) and the vehicle is in the parts catalog, its fluid specs are fetched and stored. A total miss returns a not_in_catalog result telling you to gather the data and call add_vehicle_fact — it is NOT an error.',
            input: GetVehicleFactsInput::class,
            structuredContent: false,
            processor: GetVehicleFactsProcessor::class,
            // readOnlyHint despite the carId write-through: that is transparent catalog caching,
            // not user-intent mutation — hosts should not gate a lookup behind a confirmation.
            annotations: ['readOnlyHint' => true],
        ),
        'add_vehicle_fact' => new McpTool(
            title: 'Add Vehicle Fact',
            description: 'Store a maintenance fact about a vehicle. Identify the vehicle by make+model (its row is created if unknown) OR, to update one you already saw in list_vehicles, by its vehicleId. To avoid duplicates, if make+model has no exact match but a stored vehicle looks like the same one (same model, or same carId/plate/vin), the call returns status "ambiguous_match" with candidates instead of creating: re-call with vehicleId to update the right one, or confirmNew:true if it is genuinely a different vehicle. Pass carId/plate/vin when known — they are stored and used to recognise the same vehicle later. Facts are stored unverified; a verified manufacturer/TecDoc fact is authoritative and is never overwritten (returns "refused").',
            input: AddVehicleFactInput::class,
            structuredContent: false,
            processor: AddVehicleFactProcessor::class,
            // Additive: never overwrites a verified fact (returns refused), so not destructive.
            annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
        ),
    ],
)]
final class Garage
{
}
