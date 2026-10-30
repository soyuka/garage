<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\KnownCar;
use App\Vehicle\VehicleIndex;

/**
 * @implements ProviderInterface<KnownCar>
 */
final class KnownCarProvider implements ProviderInterface
{
    public function __construct(private readonly VehicleIndex $index)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?KnownCar
    {
        $carType = $operation->getParameters()?->get('carType')?->getValue();
        $type = CarTypeResolver::fromString(\is_string($carType) ? $carType : null);
        $carId = (int) $uriVariables['carId'];

        $car = $this->index->find($carId, $type);
        if (null === $car) {
            return null;
        }

        return new KnownCar($carId, $type->value, $car['make'], $car['model'], $car['engine'], $car['fullName'], $car['yearFrom'], $car['yearTo']);
    }
}
