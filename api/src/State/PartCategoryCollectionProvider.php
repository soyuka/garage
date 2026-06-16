<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PartCategory;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<PartCategory>
 */
final class PartCategoryCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<PartCategory>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $leafOnly = filter_var($context['filters']['leafOnly'] ?? true, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;

        return $this->catalog->partCategories(
            (int) $uriVariables['carId'],
            CarTypeResolver::fromString($context['filters']['carType'] ?? null),
            $leafOnly,
            $context['filters']['search'] ?? null,
        );
    }
}
