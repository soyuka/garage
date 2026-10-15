<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\NarrowedParts;
use App\Catalog\Narrowing\ArticleNarrower;

/**
 * @implements ProviderInterface<NarrowedParts>
 */
final class NarrowedPartsProvider implements ProviderInterface
{
    public function __construct(private readonly ArticleNarrower $narrower)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NarrowedParts
    {
        $parameters = $operation->getParameters();
        $carType = $parameters?->get('carType')?->getValue();
        $make = $parameters?->get('make')?->getValue();
        $carId = (int) $uriVariables['carId'];
        $categoryId = (int) $uriVariables['categoryId'];

        return NarrowedParts::from($carId, $categoryId, $this->narrower->narrow(
            $carId,
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
            $categoryId,
            \is_string($make) ? $make : null,
        ));
    }
}
