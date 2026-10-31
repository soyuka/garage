<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Article;
use App\Bonneref\BonnerefClient;

/**
 * @implements ProviderInterface<Article>
 */
final class ArticleCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly BonnerefClient $bonneref)
    {
    }

    /**
     * @return list<Article>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $carType = $operation->getParameters()?->get('carType')?->getValue();

        return $this->bonneref->articles(
            (int) $uriVariables['carId'],
            (int) $uriVariables['categoryId'],
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
        );
    }
}
