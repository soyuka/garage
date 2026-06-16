<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Garage\VehicleType;
use App\Garage\VehicleWriteResolver;
use App\Repository\MaintenanceRecordRepository;
use App\Repository\VehicleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes and reads the append-only maintenance log. Resolves the shared vehicle through the
 * same {@see VehicleWriteResolver} as add_vehicle_fact — so logging a service can never spawn a
 * duplicate the fact path would have caught. Unlike facts, records are never updated in place:
 * each log() is a new row, so a vehicle accumulates a full service history.
 */
final class MaintenanceRecorder
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly MaintenanceRecordRepository $records,
        private readonly EntityManagerInterface $entityManager,
        private readonly VehicleWriteResolver $writeResolver,
    ) {
    }

    /**
     * @param list<array{ref: string, label?: string}> $partsUsed
     */
    public function log(
        ?int $vehicleId,
        ?string $make,
        ?string $model,
        ?int $year,
        VehicleType $vehicleType,
        ?int $carId,
        ?string $plate,
        ?string $vin,
        bool $confirmNew,
        MaintenanceType $type,
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
        $resolution = $this->writeResolver->resolveForWrite(
            $vehicleId, $make, $model, $year, $vehicleType, $carId, $plate, $vin, $confirmNew, $source,
        );

        if ('ambiguous' === $resolution->status) {
            return MaintenanceLogOutcome::ambiguous($resolution->candidates);
        }
        if ('not_found' === $resolution->status) {
            return MaintenanceLogOutcome::notFound($resolution->vehicleId);
        }

        $vehicle = $resolution->vehicle;

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

        return MaintenanceLogOutcome::recorded($record);
    }

    /**
     * Service history for a known vehicle, newest first. Returns null when the vehicle is
     * not in the garage — the caller turns that into the no_records contract (not an error).
     *
     * @return list<MaintenanceRecord>|null
     */
    public function history(
        string $make,
        string $model,
        ?int $year,
        VehicleType $vehicleType,
        ?MaintenanceType $type = null,
        ?int $limit = null,
    ): ?array {
        $vehicle = $this->vehicles->findByIdentity($make, $model, $vehicleType, $year);
        if (null === $vehicle) {
            return null;
        }

        return $this->records->history($vehicle, $type, $limit);
    }
}
