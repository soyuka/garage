<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageVehicle;
use App\Repository\VehicleRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Dereferences /garage/vehicles/{id} by loading the Entity\Vehicle behind the public ULID and
 * mapping it to the read-model. Returns null (→ 404) when the ULID matches no row.
 *
 * @implements ProviderInterface<GarageVehicle>
 */
final class GarageVehicleProvider implements ProviderInterface
{
    public function __construct(private readonly VehicleRepository $vehicles)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?GarageVehicle
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        return null === $vehicle ? null : GarageVehicle::fromEntity($vehicle);
    }
}
