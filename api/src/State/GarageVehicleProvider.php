<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageVehicle;
use App\Garage\GarageResolver;
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
final class GarageVehicleProvider implements ProviderInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly GarageVehicleView $view,
        private readonly GarageResolver $resolver,
        private readonly OwnerContext $owner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?GarageVehicle
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        // find() is by primary id, not owner-scoped — gate it so a ULID belonging to another tenant
        // reads as 404, not a cross-owner disclosure (the IDOR seam of review Root 2).
        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current()) {
            return null;
        }

        // The dashboard card reads its facts straight off this GET. Self-heal a row whose kType was
        // known but whose catalog spec sheet was never fetched, so oil capacity/spec stop showing
        // "non renseignée" when the catalog has them. No-op when already filled or no kType.
        $this->resolver->backfillCatalogSpecs($vehicle);

        $resource = GarageVehicle::fromEntity($vehicle);
        $resource->facts = $this->view->factsOf($vehicle);
        $resource->factCount = \count($resource->facts);

        return $resource;
    }
}
