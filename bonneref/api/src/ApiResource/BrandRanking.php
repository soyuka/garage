<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\CompareBrandsInput;
use App\State\CompareBrandsProcessor;

/**
 * Intent-level tool (no HTTP operations): rank spare-part brands against bonneref's curated
 * BrandTier list. Answers "which of these should I buy?" from data we actually hold, instead of
 * from the model's weights.
 */
#[ApiResource(
    mcp: [
        'compare_brands' => new McpTool(
            title: 'Compare Brands',
            description: 'Rank spare-part brands against bonneref\'s CURATED quality list — use it whenever the user weighs brands against each other ("japanparts ou ashika ?", "c\'est bien FEBEST ?"). Returns each brand with its tier (1 = OE/OEM-grade, 2 = quality aftermarket, 3 = not on our list) and `known` saying whether we actually rank it; tier 3 means UNKNOWN TO US, never "bad", and you must say so rather than imply a verdict. This is bonneref\'s own opinion, not vendor or review data — never dress it up as a spec, and never invent a reason (availability, durability, OE supplier) we hold no data for. Pair it with list_articles + its `brands` filter to say whether the catalogue even carries them for this part.',
            input: CompareBrandsInput::class,
            validate: true,
            // returns {status, source, disclaimer, ranking, best} — not a JSON-LD collection of this
            // empty class, so suppress the auto-generated output schema.
            structuredContent: false,
            processor: CompareBrandsProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => false],
        ),
    ],
)]
final class BrandRanking
{
}
