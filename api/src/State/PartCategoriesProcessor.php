<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PartCategory;
use App\Bonneref\BonnerefClient;
use App\Dto\PartCategoriesInput;
use App\Mcp\CappedResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_part_categories` tool. The TecDoc tree runs to ~500 nodes per vehicle, so the
 * result is capped and returned as a plain (envelope-free) structured payload — pass `search`
 * to narrow before hitting the cap.
 *
 * @implements ProcessorInterface<PartCategoriesInput, CallToolResult>
 */
final class PartCategoriesProcessor implements ProcessorInterface
{
    public function __construct(private readonly BonnerefClient $bonneref)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $categories = $this->bonneref->partCategories($data->carId, CarTypeResolver::fromString($data->carType), $data->leafOnly, $data->search);

        // @mago-expect analysis:less-specific-argument get_object_vars of a final DTO: the shape is sealed, mago leaves it open
        return CappedResult::of(
            array_map(static fn (PartCategory $category): array => get_object_vars($category), $categories),
            'categories',
            'Narrow with search=<term> (e.g. "filter", "oil", "brake").',
            $data->offset,
        );
    }
}
