<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Interval-derived service forecasting: for each recurring type the vehicle has a record for,
 * next-due = latest performedAt + the type's time interval. A type is forecast only when it has
 * a prior record (no history = no honest baseline) and a defined interval (recurring only).
 * Pure over the vehicle's in-memory record collection — no DB, no per-vehicle state.
 */
final readonly class DueMaintenanceCalculator
{
    public function __construct(private ClockInterface $clock = new Clock())
    {
    }

    public function forVehicle(Vehicle $vehicle): VehicleMaintenanceForecast
    {
        $now = $this->clock->now();

        /** @var array<string, MaintenanceRecord> $latestByType */
        $latestByType = [];
        foreach ($vehicle->getMaintenanceRecords() as $record) {
            // A visit covers several types; each line-item seeds the forecast for its own type.
            foreach ($record->getTypes() as $key) {
                if (!isset($latestByType[$key]) || $record->getPerformedAt() > $latestByType[$key]->getPerformedAt()) {
                    $latestByType[$key] = $record;
                }
            }
        }

        $due = [];
        $unknownBaseline = [];
        // Walk every recurring type (enum order = stable output) so a type with no record
        // becomes a surfaced gap rather than silent absence.
        foreach (MaintenanceType::cases() as $type) {
            $interval = MaintenanceInterval::forType($type);
            if (null === $interval) {
                continue;
            }

            $record = $latestByType[$type->value] ?? null;
            if (null === $record) {
                $unknownBaseline[] = $type;
                continue;
            }

            $dueAt = $record->getPerformedAt()->modify(sprintf('+%d months', $interval->months));

            $due[] = new DueMaintenance(
                type: $type,
                lastPerformedAt: $record->getPerformedAt(),
                dueAt: $dueAt,
                overdue: $dueAt < $now,
                daysUntilDue: (int) $now->diff($dueAt)->format('%r%a'),
                interval: $interval,
            );
        }

        usort($due, static fn (DueMaintenance $a, DueMaintenance $b): int => $a->dueAt <=> $b->dueAt);

        return new VehicleMaintenanceForecast($due, $unknownBaseline);
    }
}
