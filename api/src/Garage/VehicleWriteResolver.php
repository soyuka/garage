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
final readonly class VehicleWriteResolver
{
    public function __construct(
        private VehicleRepository $vehicles,
        private DocumentManager $entityManager,
        private OwnerContext $owner,
    ) {
    }

    /**
     * Saving a vehicle as such (the finder's "Enregistrer le véhicule"), where the plate is the
     * identity: several 307s are told apart by their plates, so make/model alone never binds.
     *
     *  1. vehicleId given     → that row, its missing plate/carId/vin filled in.
     *  2. same plate stored   → that row, enriched the same way.
     *  3. a candidate that could be this car (same model/carId/vin, and no plate of its own when
     *     a plate was given — a different plate is a different car) → ambiguous, unless confirmNew.
     *  4. otherwise           → a new row (created: true).
     *
     * Like resolveForWrite, nothing is flushed here.
     */
    public function resolveForSave(
        ?Ulid $vehicleId,
        ?string $make,
        ?string $model,
        VehicleType $vehicleType,
        ?int $carId,
        ?string $plate,
        ?string $vin,
        bool $confirmNew,
        string $source,
    ): VehicleResolution {
        if (null !== $vehicleId) {
            $resolution = $this->resolveForWrite($vehicleId, null, null, null, $vehicleType, null, null, null, false, $source);
            if (null !== $resolution->vehicle) {
                self::enrich($resolution->vehicle, $carId, $plate, $vin);
            }

            return $resolution;
        }

        if (null !== $plate) {
            $vehicle = $this->vehicles->findByPlate($plate);
            if (null !== $vehicle) {
                self::enrich($vehicle, $carId, null, $vin);
                $vehicle->touch();

                return VehicleResolution::resolved($vehicle);
            }
        }

        if (!$confirmNew) {
            $candidates = $this->vehicles->findCandidates((string) $make, (string) $model, $vehicleType, $carId, null, $vin);
            if (null !== $plate) {
                $candidates = array_values(array_filter($candidates, static fn (Vehicle $v): bool => null === $v->getPlate() || '' === trim($v->getPlate())));
            }
            if ([] !== $candidates) {
                return VehicleResolution::ambiguous($candidates);
            }
        }

        $vehicle = new Vehicle((string) $make, (string) $model, $vehicleType, $source, kType: $carId, vin: $vin, plate: $plate);
        $vehicle->setOwner($this->owner->require());
        $vehicle->touch();
        $this->entityManager->persist($vehicle);

        return VehicleResolution::resolved($vehicle, created: true);
    }

    private static function enrich(Vehicle $vehicle, ?int $carId, ?string $plate, ?string $vin): void
    {
        if (null !== $carId && null === $vehicle->getKType()) {
            $vehicle->setKType($carId);
        }
        if (null !== $plate && (null === $vehicle->getPlate() || '' === trim($vehicle->getPlate()))) {
            $vehicle->setPlate($plate);
        }
        if (null !== $vin && (null === $vehicle->getVin() || '' === trim($vehicle->getVin()))) {
            $vehicle->setVin($vin);
        }
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
            // Owner- and deleted-scoped: a @id pointing at another tenant's row, or at a deleted one,
            // resolves to notFound rather than letting a write land on it.
            $vehicle = $this->vehicles->findOwned($vehicleId);
            if (null === $vehicle) {
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
