<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PartCategory;
use App\Catalog\PartsCatalog;
use App\Dto\PartCategoriesInput;

/**
 * MCP `list_part_categories` tool.
 *
 * @implements ProcessorInterface<PartCategoriesInput, list<PartCategory>>
 */
final class PartCategoriesProcessor implements ProcessorInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<PartCategory>
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->partCategories($data->carId, CarTypeResolver::fromString($data->carType), $data->leafOnly);
    }
}
