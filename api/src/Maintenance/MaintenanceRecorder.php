<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Garage\VehicleWriteResolver;
use App\Repository\MaintenanceRecordRepository;
use App\Repository\PlannedMaintenanceRepository;
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
        private readonly PlannedMaintenanceRepository $plannedTasks,
        private readonly MaintenanceTypeNormalizer $typeNormalizer = new MaintenanceTypeNormalizer(),
    ) {
    }

    /**
     * Transform every done-but-unfiled task on a vehicle into a {@see MaintenanceRecord} ("entretien
     * passé"), then archive the task so it leaves the current checklist. The inverse of the
     * record→closeMatching flow: there, logging a service closes its plan; here, completing a plan
     * files it as history. Each record is stamped at $performedAt with the vehicle's CURRENT reading
     * (the odometer when the work is filed) and inherits the task's type/label/parts. Idempotent: a
     * task already archived is no longer returned by completedUnarchived(), so a re-run records
     * nothing. Dismissed tasks are excluded — abandoned work is never credited.
     *
     * $costCents is an OPTIONAL bill total for the filed visit; when the caller has none (the common
     * case today — archiving carries no bill entry), the record's cost is left null and
     * {@see MaintenanceRecord::getEffectiveCostCents()} derives an estimate from the tasks' parts.
     *
     * @return list<MaintenanceRecord> the records just created (empty when nothing was pending)
     */
    public function archiveCompletedTasks(
        Vehicle $vehicle,
        \DateTimeImmutable $performedAt,
        string $source = 'user',
        ?string $addedBy = null,
        ?int $costCents = null,
    ): array {
        $tasks = $this->plannedTasks->completedUnarchived($vehicle);
        if ([] === $tasks) {
            return [];
        }

        $usageValue = $vehicle->getUsageValue();
        $usageUnit = null !== $vehicle->getUsageUnit() ? UsageUnit::fromGarageUnit($vehicle->getUsageUnit()) : null;

        // Everything completed in one pass is filed as ONE visit (a single dated row), each task
        // contributing a line-item with its own type/label/parts — not one record per task.
        $items = array_map(static fn ($task): array => [
            'type' => $task->getType(),
            ...(null !== $task->getLabel() ? ['label' => $task->getLabel()] : []),
            'parts' => $task->getParts(),
        ], $tasks);

        $record = new MaintenanceRecord(
            vehicle: $vehicle,
            items: $items,
            performedAt: $performedAt,
            source: $source,
            usageValue: $usageValue,
            usageUnit: $usageUnit,
            costCents: $costCents,
            addedBy: $addedBy,
            owner: $vehicle->getOwner(),
        );
        $this->entityManager->persist($record);
        foreach ($tasks as $task) {
            $task->archive($performedAt);
        }

        // One flush persists the new record and the tasks' archivedAt stamps in a single unit of work.
        $this->entityManager->flush();

        return [$record];
    }

    /**
     * @param list<array{type: string, label?: string, parts?: list<array{ref: string, brand?: string, label?: string, qty?: int, price?: float}>}> $items
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
        array $items,
        \DateTimeImmutable $performedAt,
        string $source,
        ?string $label = null,
        ?int $usageValue = null,
        ?UsageUnit $usageUnit = null,
        ?string $notes = null,
        ?int $costCents = null,
        ?string $currency = null,
        ?string $performedBy = null,
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

        // A known service entered under a label/synonym ("Huile") is canonicalised to its snake_case
        // key so it matches the schedule + forecast; the long tail is kept verbatim (never rejected).
        $items = $this->typeNormalizer->normalizeItems($items);

        // The odometer/hours noted on a service is a fresh reading of the vehicle itself: promote it
        // onto the vehicle's current usage (monotonic — a backdated log never rewinds it). The record
        // below keeps its own usageValue as the historical reading at that service.
        if (null !== $usageValue && null !== $garageUnit) {
            $vehicle->recordUsageReading($usageValue, $garageUnit);
        }

        $record = new MaintenanceRecord(
            vehicle: $vehicle,
            items: $items,
            performedAt: $performedAt,
            source: $source,
            label: $label,
            usageValue: $usageValue,
            usageUnit: $usageUnit,
            notes: $notes,
            costCents: $costCents,
            currency: $currency,
            performedBy: $performedBy,
            addedBy: $addedBy,
            owner: $vehicle->getOwner(),
        );
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        // Performing a service clears any open plan for each type it covered off the checklist.
        foreach ($record->getTypes() as $type) {
            $this->planner->closeMatching($vehicle, $type, $performedAt);
        }

        return MaintenanceLogOutcome::recorded($record);
    }

    /**
     * Edit a logged visit in place — the one exception to the append-only rule, shared by the
     * dashboard's PATCH and the edit_maintenance MCP tool. $changes is SPARSE: only the keys present
     * are applied, so a caller can patch a single field (MCP) or replace the whole visit (the
     * dashboard editor holds full state and sends every key). Recognised keys mirror the record's
     * mutators — items, performedAt, usageValue, usageUnit, label, notes, performedBy, costCents,
     * currency — each already converted to its domain type by the caller (the unit vocabulary and
     * error wording differ per surface, so normalisation stays caller-side). The vehicle's own usage
     * reading is deliberately NOT promoted here: an edit corrects history, it never advances the
     * odometer (unlike {@see log()}).
     *
     * @param array{
     *     items?: list<array{type: string, label?: string, parts?: list<array{ref: string, brand?: string, label?: string, qty?: int, price?: float}>}>,
     *     performedAt?: \DateTimeImmutable,
     *     usageValue?: int|null,
     *     usageUnit?: UsageUnit|null,
     *     label?: string|null,
     *     notes?: string|null,
     *     performedBy?: string|null,
     *     costCents?: int|null,
     *     currency?: string|null
     * } $changes
     */
    public function edit(MaintenanceRecord $record, array $changes): void
    {
        if (\array_key_exists('items', $changes)) {
            $record->replaceItems($this->typeNormalizer->normalizeItems($changes['items']));
        }
        if (\array_key_exists('performedAt', $changes)) {
            $record->setPerformedAt($changes['performedAt']);
        }
        if (\array_key_exists('usageValue', $changes)) {
            $record->setUsageValue($changes['usageValue']);
        }
        if (\array_key_exists('usageUnit', $changes)) {
            $record->setUsageUnit($changes['usageUnit']);
        }
        if (\array_key_exists('label', $changes)) {
            $record->setLabel($changes['label']);
        }
        if (\array_key_exists('notes', $changes)) {
            $record->setNotes($changes['notes']);
        }
        if (\array_key_exists('performedBy', $changes)) {
            $record->setPerformedBy($changes['performedBy']);
        }
        if (\array_key_exists('costCents', $changes)) {
            $record->setCostCents($changes['costCents']);
        }
        if (\array_key_exists('currency', $changes)) {
            $record->setCurrency($changes['currency']);
        }

        $this->entityManager->flush();
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
