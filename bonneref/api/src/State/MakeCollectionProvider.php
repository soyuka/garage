<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Make;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<Make>
 */
final readonly class MakeCollectionProvider implements ProviderInterface
{
    public function __construct(
        private PartsCatalog $catalog,
        private Pagination $pagination,
    ) {
    }

    /**
     * @return ArrayPaginator<Make>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArrayPaginator
    {
        $parameters = $operation->getParameters();
        $carType = $parameters?->get('carType')?->getValue();
        $search = $parameters?->get('search')?->getValue();

        $rows = $this->catalog->makes(
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
            \is_string($search) ? $search : null,
        );
        [, $offset, $limit] = $this->pagination->getPagination($operation, $context);

        return new ArrayPaginator($rows, $offset, $limit);
    }
}
