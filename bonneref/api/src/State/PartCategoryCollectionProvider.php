<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PartCategory;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<PartCategory>
 */
final readonly class PartCategoryCollectionProvider implements ProviderInterface
{
    public function __construct(
        private PartsCatalog $catalog,
        private Pagination $pagination,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArrayPaginator
    {
        $parameters = $operation->getParameters();
        $carType = $parameters?->get('carType')?->getValue();
        $leafOnly = $parameters?->get('leafOnly')?->getValue();
        $search = $parameters?->get('search')?->getValue();

        $rows = $this->catalog->partCategories(
            (int) $uriVariables['carId'],
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
            filter_var($leafOnly, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true,
            \is_string($search) ? $search : null,
        );
        [, $offset, $limit] = $this->pagination->getPagination($operation, $context);

        return new ArrayPaginator($rows, (int) $offset, (int) $limit);
    }
}
