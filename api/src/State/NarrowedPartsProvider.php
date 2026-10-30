<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\NarrowedParts;
use App\Bonneref\BonnerefClient;

/**
 * @implements ProviderInterface<NarrowedParts>
 */
final class NarrowedPartsProvider implements ProviderInterface
{
    public function __construct(private readonly BonnerefClient $bonneref)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NarrowedParts
    {
        $parameters = $operation->getParameters();
        $carType = $parameters?->get('carType')?->getValue();
        $make = $parameters?->get('make')?->getValue();
        $productGroupId = $parameters?->get('productGroupId')?->getValue();

        return $this->bonneref->narrowed(
            (int) $uriVariables['carId'],
            (int) $uriVariables['categoryId'],
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
            \is_string($make) ? $make : null,
            is_numeric($productGroupId) ? (int) $productGroupId : null,
        );
    }
}
