<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PartCategory;
use App\Bonneref\BonnerefClient;

/**
 * @implements ProviderInterface<PartCategory>
 */
final class PartCategoryCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly BonnerefClient $bonneref)
    {
    }

    /**
     * @return list<PartCategory>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $parameters = $operation->getParameters();
        $carType = $parameters?->get('carType')?->getValue();
        $leafOnly = $parameters?->get('leafOnly')?->getValue();
        $search = $parameters?->get('search')?->getValue();

        return $this->bonneref->partCategories(
            (int) $uriVariables['carId'],
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
            filter_var($leafOnly, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true,
            \is_string($search) ? $search : null,
        );
    }
}
