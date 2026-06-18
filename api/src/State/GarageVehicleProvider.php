<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageVehicle;
use App\Garage\GarageVehicleView;
use App\Repository\VehicleRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Dereferences /garage/vehicles/{id} by loading the Entity\Vehicle behind the public ULID and
 * mapping it to the read-model. Returns null (→ 404) when the ULID matches no row. The item read
 * inlines the vehicle's facts (the listing does not), so the dashboard card shows them with
 * provenance without a second request.
 *
 * @implements ProviderInterface<GarageVehicle>
 */
final class GarageVehicleProvider implements ProviderInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly GarageVehicleView $view,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?GarageVehicle
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle) {
            return null;
        }

        $resource = GarageVehicle::fromEntity($vehicle);
        $resource->facts = $this->view->factsOf($vehicle);

        return $resource;
    }
}
