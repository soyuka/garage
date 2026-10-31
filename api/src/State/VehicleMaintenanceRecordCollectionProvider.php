<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageMaintenanceRecord;
use App\Repository\MaintenanceRecordRepository;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Symfony\Component\Uid\Ulid;

/**
 * Lists a vehicle's past maintenance at /garage/vehicles/{vehicleId}/maintenance-records — the
 * dashboard's "past maintenance" rail reads it on select. Newest service first. Owner-gated: a
 * foreign or soft-deleted vehicle yields an empty collection, never another tenant's history.
 *
 * @implements ProviderInterface<GarageMaintenanceRecord>
 */
final readonly class VehicleMaintenanceRecordCollectionProvider implements ProviderInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private MaintenanceRecordRepository $records,
        private OwnerContext $owner,
    ) {
    }

    /**
     * @return list<GarageMaintenanceRecord>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $id = $uriVariables['vehicleId'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted()) {
            return [];
        }

        return array_map(GarageMaintenanceRecord::fromEntity(...), $this->records->history($vehicle));
    }
}
