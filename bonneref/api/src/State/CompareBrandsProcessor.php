<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Catalog\BrandTier;
use App\Dto\CompareBrandsInput;
use App\Mcp\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `compare_brands`: rank spare-part brands against bonneref's own curated list.
 *
 * It exists because "japanparts ou ashika, tu conseilles quoi ?" was answered from the model's weights,
 * with a justification (availability for Mazda) backed by no data we hold — while BrandTier already
 * carried the answer. The payload is explicit that this is a CURATED opinion, not vendor data, and that
 * an unlisted brand is unknown to us rather than bad.
 *
 * @implements ProcessorInterface<CompareBrandsInput, CallToolResult>
 */
final readonly class CompareBrandsProcessor implements ProcessorInterface
{
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $brands = array_values(array_filter(array_map(trim(...), $data->brands), static fn (string $b): bool => '' !== $b));
        if ([] === $brands) {
            return ToolResult::error('invalid_input', 'pass at least one brand to rank.');
        }

        $ranking = BrandTier::rankAll($brands);
        $known = array_values(array_filter($ranking, static fn (array $r): bool => $r['known']));

        return ToolResult::of([
            'status' => 'ok',
            'source' => 'curated',
            'disclaimer' => "This ranking is bonneref's own curated list, not vendor or review data. Tier 1 = OE/OEM-grade, tier 2 = quality aftermarket, tier 3 = NOT ON THE LIST — unknown to us, which is not a verdict on the brand. Say which it is when you answer.",
            'ranking' => $ranking,
            'best' => $known[0]['brand'] ?? null,
        ]);
    }
}
