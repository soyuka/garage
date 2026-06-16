<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageVehicleFact;
use App\Repository\VehicleFactRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Dereferences /garage/vehicle-facts/{id} by ULID. Returns null (→ 404) on no match.
 *
 * @implements ProviderInterface<GarageVehicleFact>
 */
final class GarageVehicleFactProvider implements ProviderInterface
{
    public function __construct(private readonly VehicleFactRepository $facts)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?GarageVehicleFact
    {
        $id = $uriVariables['id'];
        $fact = $this->facts->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        return null === $fact ? null : GarageVehicleFact::fromEntity($fact);
    }
}
