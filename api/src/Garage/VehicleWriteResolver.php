<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * The single dedup brain for every garage write. Decides which vehicle a planned item or
 * maintenance record lands on so the write paths (plan_maintenance, log_maintenance) never
 * disagree and never silently spawn a duplicate:
 *
 *  1. vehicleId given   → that exact row (the agent reconciled an earlier ambiguity).
 *  2. exact identity     → the matching (make, model, type, year) row.
 *  3. near-matches exist → returned as candidates (no write) for the agent to reconcile,
 *                          unless confirmNew forces a fresh row.
 *  4. otherwise          → a new row, promoting carId/plate/vin onto its identity columns and
 *                          seeding any usage reading the caller carried.
 *
 * A freshly created vehicle is persisted but NOT flushed — the caller persists its own child
 * (fact or record) and flushes once.
 */
final class VehicleWriteResolver
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DocumentManager $entityManager,
        private readonly OwnerContext $owner,
    ) {
    }

    public function resolveForWrite(
        ?Ulid $vehicleId,
        ?string $make,
        ?string $model,
        ?int $year,
        VehicleType $vehicleType,
        ?int $carId,
        ?string $plate,
        ?string $vin,
        bool $confirmNew,
        string $source,
        ?int $usageValue = null,
        ?UsageUnit $usageUnit = null,
    ): VehicleResolution {
        if (null !== $vehicleId) {
            $vehicle = $this->vehicles->find($vehicleId);

            // find() is by id, not owner-scoped: a @id pointing at another tenant's row resolves to
            // notFound rather than letting a write land on a vehicle the caller does not own.
            if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current()) {
                return VehicleResolution::notFound($vehicleId);
            }

            // Every resolve-for-write is activity on the vehicle: stamp it so the parc orders
            // most-recently-touched first (the caller flushes its own unit of work).
            $vehicle->touch();

            return VehicleResolution::resolved($vehicle);
        }

        $vehicle = $this->vehicles->findByIdentity($make, $model, $vehicleType, $year);
        if (null !== $vehicle) {
            $vehicle->touch();

            return VehicleResolution::resolved($vehicle);
        }

        if (!$confirmNew) {
            $candidates = $this->vehicles->findCandidates($make, $model, $vehicleType, $carId, $plate, $vin);
            if ([] !== $candidates) {
                return VehicleResolution::ambiguous($candidates);
            }
        }

        $vehicle = new Vehicle($make, $model, $vehicleType, $source, year: $year, kType: $carId, vin: $vin, plate: $plate);
        $vehicle->setOwner($this->owner->require());
        // A brand-new vehicle is the most recent activity in the parc — stamp it so it sorts first
        // (without this it would have a null updatedAt and sink below already-touched vehicles).
        $vehicle->touch();
        if (null !== $usageValue && null !== $usageUnit) {
            $vehicle->setUsage($usageValue, $usageUnit);
        }
        $this->entityManager->persist($vehicle);

        return VehicleResolution::resolved($vehicle);
    }
}
