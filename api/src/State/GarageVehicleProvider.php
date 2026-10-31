<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageVehicle;
use App\Garage\GarageVehicleView;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Symfony\Component\Uid\Ulid;

/**
 * Dereferences /garage/vehicles/{id} by loading the Entity\Vehicle behind the public ULID and
 * mapping it to the read-model. Returns null (→ 404) when the ULID matches no row. The item read
 * inlines the vehicle's facts (the listing does not), so the dashboard card shows them with
 * provenance without a second request.
 *
 * @implements ProviderInterface<GarageVehicle>
 */
final readonly class GarageVehicleProvider implements ProviderInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private GarageVehicleView $view,
        private OwnerContext $owner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?GarageVehicle
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        // find() is by primary id, not owner-scoped — gate it so a ULID belonging to another tenant
        // reads as 404, not a cross-owner disclosure (the IDOR seam of review Root 2). A soft-deleted
        // row reads as 404 too: the tombstone keeps its history but it is gone from every read path.
        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted()) {
            return null;
        }

        $resource = GarageVehicle::fromEntity($vehicle);
        $resource->facts = $this->view->factsOf($vehicle);
        $resource->factCount = \count($resource->facts);
        $resource->health = $this->view->healthOf($vehicle);

        return $resource;
    }
}
