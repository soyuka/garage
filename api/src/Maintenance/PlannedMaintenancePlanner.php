<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\DismissMaintenanceOutcome;
use App\Garage\PlanMaintenanceOutcome;
use App\Garage\UsageUnit;
use App\Garage\VehicleType;
use App\Garage\VehicleWriteResolver;
use App\Repository\PlannedMaintenanceRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * Writes the planned-task checklist. Resolves the vehicle through the same {@see VehicleWriteResolver}
 * as the fact/record paths, so planning can never spawn a duplicate vehicle. Unlike the append-only
 * log, planning is an UPSERT: re-running a turn for the same (vehicle, type, label) refreshes the
 * one open task instead of stacking duplicates — the agent re-emits the same plan when it re-reads a
 * service, and the checklist must stay a set, not a pile.
 */
final class PlannedMaintenancePlanner
{
    public function __construct(
        private readonly VehicleWriteResolver $writeResolver,
        private readonly PlannedMaintenanceRepository $tasks,
        private readonly DocumentManager $entityManager,
    ) {
    }

    /**
     * @param list<array{ref: string, label?: string, brand?: string, qty?: int, price?: float, supplier?: string}> $parts
     */
    public function plan(
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
        string $source,
        ?string $label = null,
        ?int $targetUsageValue = null,
        ?UsageUnit $targetUsageUnit = null,
        ?\DateTimeImmutable $dueDate = null,
        array $parts = [],
        ?string $addedBy = null,
    ): PlanMaintenanceOutcome {
        $resolution = $this->writeResolver->resolveForWrite(
            $vehicleId, $make, $model, $year, $vehicleType, $carId, $plate, $vin, $confirmNew, $source,
        );

        if ('ambiguous' === $resolution->status) {
            return PlanMaintenanceOutcome::ambiguous($resolution->candidates);
        }
        if ('not_found' === $resolution->status) {
            return PlanMaintenanceOutcome::notFound($resolution->vehicleId);
        }

        $vehicle = $resolution->vehicle;

        $task = $this->tasks->findOpen($vehicle, $type, $label);
        if (null === $task) {
            $task = new PlannedMaintenance(
                vehicle: $vehicle,
                type: $type,
                source: $source,
                label: $label,
                targetUsageValue: $targetUsageValue,
                targetUsageUnit: $targetUsageUnit,
                dueDate: $dueDate,
                parts: $parts,
                addedBy: $addedBy,
                owner: $vehicle->getOwner(),
            );
            $this->entityManager->persist($task);
        } else {
            // Upsert: only overwrite a field the caller actually re-supplied, so a partial re-plan
            // (e.g. fresh parts, no target) does not wipe a target set on the first pass.
            if (null !== $targetUsageValue || null !== $targetUsageUnit) {
                $task->setTarget($targetUsageValue, $targetUsageUnit);
            }
            if (null !== $dueDate) {
                $task->setDueDate($dueDate);
            }
            if ([] !== $parts) {
                $task->mergeParts($parts);
            }
        }

        $this->entityManager->flush();

        return PlanMaintenanceOutcome::planned($task);
    }

    /**
     * Soft-remove a checklist task: resolve the vehicle through the same dedup brain, find its one
     * open task for (type, label), and flip it to Dismissed — it leaves the live checklist but stays
     * in the DB (recoverable, surfaced by the "show hidden" view). Returns task_not_found when the
     * vehicle resolves but carries no such open task — there is nothing to remove, never an upsert.
     */
    public function dismiss(
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
        string $source,
        ?string $label = null,
    ): DismissMaintenanceOutcome {
        $resolution = $this->writeResolver->resolveForWrite(
            $vehicleId, $make, $model, $year, $vehicleType, $carId, $plate, $vin, $confirmNew, $source,
        );

        if ('ambiguous' === $resolution->status) {
            return DismissMaintenanceOutcome::ambiguous($resolution->candidates);
        }
        if ('not_found' === $resolution->status) {
            return DismissMaintenanceOutcome::notFound($resolution->vehicleId);
        }

        $task = $this->tasks->findOpen($resolution->vehicle, $type, $label);
        if (null === $task) {
            return DismissMaintenanceOutcome::taskNotFound();
        }

        $task->close(PlannedMaintenanceStatus::Dismissed, new \DateTimeImmutable());
        $this->entityManager->flush();

        return DismissMaintenanceOutcome::dismissed($task);
    }

    /**
     * Close every open task of a type on a vehicle as Done — called when that service is logged, so
     * performing the work clears it off the checklist. Returns how many were closed.
     */
    public function closeMatching(Vehicle $vehicle, string $type, \DateTimeImmutable $at): int
    {
        $open = $this->tasks->openOfType($vehicle, $type);
        foreach ($open as $task) {
            $task->close(PlannedMaintenanceStatus::Done, $at);
        }
        if ([] !== $open) {
            $this->entityManager->flush();
        }

        return \count($open);
    }
}
