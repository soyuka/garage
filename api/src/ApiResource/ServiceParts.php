<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\ServicePartsInput;
use App\State\ServicePartsProcessor;

/**
 * Intent-level catalog tool (no HTTP operations): bundles the part categories, their
 * articles and the relevant fluid specs for a maintenance service in a single call,
 * so the host avoids walking list_part_categories → list_articles → get_fill_volumes.
 */
#[ApiResource(
    mcp: [
        'get_service_parts' => new McpTool(
            title: 'Service Parts',
            description: 'Get everything needed for a maintenance service on a vehicle in one call: the relevant part categories with their aftermarket articles, plus the matching fluid specs. service is one of oil_change (oil filter + engine-oil capacity/spec), filters (oil/air/fuel/cabin), brakes (+ brake fluid) or spark_plugs. Use carId/carType from lookup_plate. Prefer this over walking list_part_categories → list_articles → get_fill_volumes by hand.',
            input: ServicePartsInput::class,
            // returns a heterogeneous {status, service, fluids, categories} CallToolResult —
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
