<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\AddVehicleFactInput;
use App\Dto\GetVehicleFactsInput;
use App\State\AddVehicleFactProcessor;
use App\State\GetVehicleFactsProcessor;

/**
 * The garage: a self-strengthening vehicle store exposed as MCP tools (no HTTP operations
 * in phase 1). get_vehicle_facts reads shared garage → upstream (write-through) → miss;
 * add_vehicle_fact lets the LLM fill catalog misses, with provenance and a precedence rule
 * that never lets an llm/user value overwrite a verified authoritative fact.
 */
#[ApiResource(
    mcp: [
        // structuredContent: false suppresses the auto-generated JSON-LD output schema
        // (which would be built from the empty Garage class and wrongly advertise @id/@type).
        // The processors return a CallToolResult directly — short-circuiting the structured
        // content processor — so they still populate structuredContent at runtime.
        'get_vehicle_facts' => new McpTool(
            description: 'Get maintenance facts (oil type/capacity, coolant, brake fluid, spark plug…) for a vehicle, each tagged with provenance (source, verified) so you can caveat: "per TecDoc" vs "general knowledge, NOT manufacturer-verified". Known vehicles return their stored facts. If you pass carId (TecDoc kType) and the vehicle is in the parts catalog, its fluid specs are fetched and stored. A total miss returns a not_in_catalog result telling you to gather the data and call add_vehicle_fact — it is NOT an error.',
            input: GetVehicleFactsInput::class,
            structuredContent: false,
            processor: GetVehicleFactsProcessor::class,
        ),
        'add_vehicle_fact' => new McpTool(
            description: 'Store a maintenance fact about a vehicle (creating it if unknown). Use this to fill catalog misses with general knowledge or a web source; facts are stored unverified. A verified manufacturer/TecDoc fact is authoritative and will never be overwritten — such an attempt returns a refused result naming the existing fact.',
            input: AddVehicleFactInput::class,
            structuredContent: false,
            processor: AddVehicleFactProcessor::class,
        ),
    ],
)]
final class Garage
{
}
