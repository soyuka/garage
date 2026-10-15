<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Car;
use App\Catalog\CarFilter;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<Car>
 */
final class CarCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<Car>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $parameters = $operation->getParameters();
        $value = static fn (string $key): mixed => $parameters?->get($key)?->getValue();
        $carType = $value('carType');
        $year = $value('year');
        $litres = $value('capacityLitres');
        $fuel = $value('fuel');
        $search = $value('search');

        $cars = $this->catalog->cars(
            (int) $uriVariables['makeId'],
            (int) $uriVariables['modelId'],
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
        );

        return CarFilter::apply(
            $cars,
            is_numeric($year) ? (int) $year : null,
            is_numeric($litres) ? (float) $litres : null,
            \is_string($fuel) ? $fuel : null,
            \is_string($search) ? $search : null,
        );
    }
}
