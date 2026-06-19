<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\MotulOilInput;
use App\State\MotulOilProcessor;

/**
 * MCP `get_motul_oil` tool: the manufacturer-grade ENGINE OIL VISCOSITY (e.g. 5W-30, 10W-40) that
 * the TecDoc fill-volumes catalog does NOT carry — it only gives the ACEA/API quality class. Reads
 * Motul's Oil Advisor, which maps a vehicle+year to the recommended SAE grade. Brand-neutral: it
 * returns the GRADE and ACEA/API quality, not a Motul product to buy.
 */
#[ApiResource(
    mcp: [
        'get_motul_oil' => new McpTool(
            title: 'Engine Oil Viscosity (Motul)',
            description: 'Look up the recommended engine-oil SAE VISCOSITY GRADE (e.g. 5W-30, 10W-40) for a vehicle. Use this for "10W30 ou 10W40 ?", "quelle huile / quelle viscosité" — the kind of question get_vehicle_facts/get_fill_volumes can NOT answer (those only give the ACEA quality class). Pass search (make + model, e.g. "Mazda 6"), and — strongly recommended — the year and engine to pin the exact variant. Returns status "matched" with engineOilGrades + a sourceUrl, "ambiguous" with candidates (each carrying an `id`) when variants tie on different grades, or "not_found". To resolve an ambiguous result, re-call with motulId set to the chosen candidate\'s `id` — it pins that exact variant directly (no search/year/engine needed) and returns its grade. Persist a matched grade as a plan_maintenance attribute (key oil_viscosity, source "motul", url the returned sourceUrl).',
            input: MotulOilInput::class,
            structuredContent: false,
            processor: MotulOilProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
        ),
    ],
)]
final class MotulOil
{
}
