<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Article;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<Article>
 */
final readonly class ArticleCollectionProvider implements ProviderInterface
{
    public function __construct(private PartsCatalog $catalog)
    {
    }

    /**
     * @return list<Article>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $carType = $operation->getParameters()?->get('carType')?->getValue();

        return $this->catalog->articles(
            (int) $uriVariables['carId'],
            (int) $uriVariables['categoryId'],
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
        );
    }
}
