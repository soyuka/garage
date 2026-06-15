<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Repository\MaintenanceRecordRepository;
use App\Repository\VehicleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes and reads the append-only maintenance log. Mirrors App\Garage\GarageResolver:
 * resolves the shared vehicle (creating it if absent, same find-or-create as addFact) and
 * reads its history. Unlike facts, records are never updated in place — each log() is a new
 * row, so a vehicle accumulates a full service history.
 */
final class MaintenanceRecorder
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly MaintenanceRecordRepository $records,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array{ref: string, label?: string}> $partsUsed
     */
    public function log(
        string $make,
        string $model,
        ?int $year,
        VehicleType $vehicleType,
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
    ): MaintenanceRecord {
        $vehicle = $this->vehicles->findByIdentity($make, $model, $vehicleType, $year);
        if (null === $vehicle) {
            $vehicle = new Vehicle($make, $model, $vehicleType, $source, year: $year);
            $this->entityManager->persist($vehicle);
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

        return $record;
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
