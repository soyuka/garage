<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\ServicePartsInput;
use App\State\ServicePartsProcessor;

/**
 * Intent-level catalog tool (no HTTP operations): bundles the part categories, their
 * articles for a maintenance service in a single call,
 * so the host avoids walking list_part_categories → list_articles.
 */
#[ApiResource(
    mcp: [
        'get_service_parts' => new McpTool(
            title: 'Service Parts',
            description: 'Get everything needed for a maintenance service on a vehicle in one call: the relevant part categories with their aftermarket articles. service is one of oil_change (oil filter), filters (oil/air/fuel/cabin), brakes or spark_plugs. Fluid capacities and grades are not part of the result: read them with get_vehicle_facts / get_motul_oil. Use carId/carType from lookup_plate. Prefer this over walking list_part_categories → list_articles by hand.',
            input: ServicePartsInput::class,
            // returns a heterogeneous {status, service, carId, categories} CallToolResult —
            // suppress the auto JSON-LD output schema (built from this empty class otherwise).
            structuredContent: false,
            processor: ServicePartsProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
        ),
    ],
)]
final class ServiceParts
{
}
