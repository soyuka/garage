<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * The single dedup brain for every garage write. Decides which vehicle a fact or maintenance
 * record lands on so the two write paths (add_vehicle_fact, log_maintenance) never disagree
 * and never silently spawn a duplicate:
 *
 *  1. vehicleId given   → that exact row (the agent reconciled an earlier ambiguity).
 *  2. exact identity     → the matching (make, model, type, year) row.
 *  3. near-matches exist → returned as candidates (no write) for the agent to reconcile,
 *                          unless confirmNew forces a fresh row.
 *  4. otherwise          → a new row, promoting carId/plate/vin onto its identity columns.
 *
 * A freshly created vehicle is persisted but NOT flushed — the caller persists its own child
 * (fact or record) and flushes once.
 */
final class VehicleWriteResolver
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly EntityManagerInterface $entityManager,
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
    ): VehicleResolution {
        if (null !== $vehicleId) {
            $vehicle = $this->vehicles->find($vehicleId);

            return null === $vehicle
                ? VehicleResolution::notFound($vehicleId)
                : VehicleResolution::resolved($vehicle);
        }

        $vehicle = $this->vehicles->findByIdentity($make, $model, $vehicleType, $year);
        if (null !== $vehicle) {
            return VehicleResolution::resolved($vehicle);
        }

        if (!$confirmNew) {
            $candidates = $this->vehicles->findCandidates($make, $model, $vehicleType, $carId, $plate, $vin);
            if ([] !== $candidates) {
                return VehicleResolution::ambiguous($candidates);
            }
        }

        $vehicle = new Vehicle($make, $model, $vehicleType, $source, year: $year, kType: $carId, vin: $vin, plate: $plate);
        $this->entityManager->persist($vehicle);

        return VehicleResolution::resolved($vehicle);
    }
}
