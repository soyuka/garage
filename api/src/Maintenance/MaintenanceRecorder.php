<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Garage\VehicleType;
use App\Garage\VehicleWriteResolver;
use App\Repository\MaintenanceRecordRepository;
use App\Repository\VehicleRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * Writes and reads the append-only maintenance log. Resolves the shared vehicle through the
 * same {@see VehicleWriteResolver} as plan_maintenance — so logging a service can never spawn a
 * duplicate the planning path would have caught. Unlike planned items, records are never updated
 * in place: each log() is a new row, so a vehicle accumulates a full service history.
 */
final class MaintenanceRecorder
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly MaintenanceRecordRepository $records,
        private readonly DocumentManager $entityManager,
        private readonly VehicleWriteResolver $writeResolver,
        private readonly PlannedMaintenancePlanner $planner,
    ) {
    }

    /**
     * @param list<array{ref: string, label?: string}> $partsUsed
     */
    public function log(
        ?Ulid $vehicleId,
        ?string $make,
        ?string $model,
        ?int $year,
        VehicleType $vehicleType,
        ?int $carId,
        ?string $plate,
        ?string $vin,
        bool $confirmNew,
        string $type,
        \DateTimeImmutable $performedAt,
        string $source,
        ?string $label = null,
        ?int $usageValue = null,
        ?UsageUnit $usageUnit = null,
        ?string $notes = null,
        ?int $costCents = null,
        ?string $currency = null,
        ?string $performedBy = null,
        array $partsUsed = [],
        ?string $addedBy = null,
    ): MaintenanceLogOutcome {
        // The maintenance log and the garage store spell engine hours differently ('hours' vs 'h'),
        // so cross to the garage-side unit before seeding/promoting the vehicle's own reading.
        $garageUnit = $usageUnit?->toGarageUnit();

        $resolution = $this->writeResolver->resolveForWrite(
            $vehicleId, $make, $model, $year, $vehicleType, $carId, $plate, $vin, $confirmNew, $source, $usageValue, $garageUnit,
        );

        if ('ambiguous' === $resolution->status) {
            return MaintenanceLogOutcome::ambiguous($resolution->candidates);
        }
        if ('not_found' === $resolution->status) {
            return MaintenanceLogOutcome::notFound($resolution->vehicleId);
        }

        $vehicle = $resolution->vehicle;

        // The odometer/hours noted on a service is a fresh reading of the vehicle itself: promote it
        // onto the vehicle's current usage (monotonic — a backdated log never rewinds it). The record
        // below keeps its own usageValue as the historical reading at that service.
        if (null !== $usageValue && null !== $garageUnit) {
            $vehicle->recordUsageReading($usageValue, $garageUnit);
        }

        $record = new MaintenanceRecord(
            vehicle: $vehicle,
            type: $type,
            performedAt: $performedAt,
            source: $source,
            label: $label,
            usageValue: $usageValue,
            usageUnit: $usageUnit,
            notes: $notes,
            costCents: $costCents,
            currency: $currency,
            performedBy: $performedBy,
            partsUsed: $partsUsed,
            addedBy: $addedBy,
        );
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        // Performing a service clears any open plan for it off the checklist.
        $this->planner->closeMatching($vehicle, $type, $performedAt);

        return MaintenanceLogOutcome::recorded($record);
    }

    /**
     * Service history for a known vehicle, newest first — addressed by its @id ($vehicleId) or by
     * make+model identity. Returns null when the vehicle is not in the garage — the caller turns
     * that into the no_records contract (not an error).
     *
     * @return list<MaintenanceRecord>|null
     */
    public function history(
        ?Ulid $vehicleId,
        ?string $make,
        ?string $model,
        ?int $year,
        VehicleType $vehicleType,
        ?string $type = null,
        ?int $limit = null,
    ): ?array {
        $vehicle = null !== $vehicleId
            ? $this->vehicles->find($vehicleId)
            : $this->vehicles->findByIdentity($make, $model, $vehicleType, $year);
        if (null === $vehicle) {
            return null;
        }

        return $this->records->history($vehicle, $type, $limit);
    }
}
