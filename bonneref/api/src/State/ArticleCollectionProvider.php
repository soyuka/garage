<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Article;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<Article>
 */
final readonly class ArticleCollectionProvider implements ProviderInterface
{
    public function __construct(
        private PartsCatalog $catalog,
        private Pagination $pagination,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArrayPaginator
    {
        $carType = $operation->getParameters()?->get('carType')?->getValue();

        $rows = $this->catalog->articles(
            (int) $uriVariables['carId'],
            (int) $uriVariables['categoryId'],
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
        );
        [, $offset, $limit] = $this->pagination->getPagination($operation, $context);

        return new ArrayPaginator($rows, (int) $offset, (int) $limit);
    }
}
