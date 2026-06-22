<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Entity\ServiceInterval;
use App\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Service forecasting from three layers: the per-vehicle {@see ServiceInterval} SCHEDULE (the rule),
 * the {@see MaintenanceRecord} history (the baseline), and the vehicle's current reading (the usage
 * axis). Precedence mirrors the facts projection: a per-vehicle schedule rule overrides the generic
 * {@see MaintenanceInterval} default for the same type; types with neither a rule nor a record become
 * a surfaced gap (no honest baseline = no forecast) rather than silent absence.
 *
 * For a rule with a usage cadence the forecast measures the hours/km axis directly (the only axis
 * that matters for the hour-metered TORO), and — when the history yields a usage rate — projects it
 * onto the calendar. Pure over the vehicle's in-memory collections: no DB, no per-vehicle state.
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

        $currentUsage = $this->currentUsage($vehicle);

        $due = [];
        $unknownBaseline = [];
        /** @var array<string, true> $covered the enum types the schedule already answers for */
        $covered = [];

        // 1. Per-vehicle schedule rules take precedence over the generic defaults.
        foreach ($vehicle->serviceSchedule() as $interval) {
            $type = MaintenanceType::tryFrom($interval->getType());
            if (null !== $type) {
                $covered[$type->value] = true;
            }

            $record = $latestByType[$interval->getType()] ?? null;
            if (null === $record) {
                // We know the cadence but have no logged baseline — an honest gap, not a guess. Only
                // enum types surface here (unknownBaseline is typed); the long tail is dropped for now.
                if (null !== $type && !\in_array($type, $unknownBaseline, true)) {
                    $unknownBaseline[] = $type;
                }
                continue;
            }

            $due[] = $this->dueFromSchedule($type ?? MaintenanceType::Other, $interval, $record, $currentUsage, $vehicle, $now);
        }

        // 2. Generic fallback for the known recurring types the schedule does not cover.
        foreach (MaintenanceType::cases() as $type) {
            if (isset($covered[$type->value])) {
                continue;
            }
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

        // Soonest due first; usage-only items with no calendar projection sort last.
        usort($due, static function (DueMaintenance $a, DueMaintenance $b): int {
            $da = $a->effectiveDueAt();
            $db = $b->effectiveDueAt();

            return match (true) {
                null === $da && null === $db => 0,
                null === $da => 1,
                null === $db => -1,
                default => $da <=> $db,
            };
        });

        return new VehicleMaintenanceForecast($due, $unknownBaseline);
    }

    /**
     * Forecast one schedule rule on both axes (time + usage), reported together. Time needs the
     * rule's month interval; usage needs a same-unit reading on the baseline record and on the
     * vehicle. With enough same-unit history the usage axis is projected onto the calendar.
     */
    private function dueFromSchedule(
        MaintenanceType $type,
        ServiceInterval $interval,
        MaintenanceRecord $record,
        ?CurrentUsage $currentUsage,
        Vehicle $vehicle,
        \DateTimeImmutable $now,
    ): DueMaintenance {
        $lastPerformedAt = $record->getPerformedAt();

        $dueAt = null;
        $daysUntilDue = null;
        $timeOverdue = false;
        if (null !== $interval->getEveryMonths()) {
            $dueAt = $lastPerformedAt->modify(sprintf('+%d months', $interval->getEveryMonths()));
            $daysUntilDue = (int) $now->diff($dueAt)->format('%r%a');
            $timeOverdue = $dueAt < $now;
        }

        $lastUsageValue = null;
        $dueUsageValue = null;
        $usageRemaining = null;
        $usageOverdue = false;
        $projectedDueAt = null;

        $everyUsage = $interval->getEveryUsageValue();
        $unit = $interval->getEveryUsageUnit();
        if (null !== $everyUsage && null !== $unit
            && null !== $record->getUsageValue() && $record->getUsageUnit() === $unit
        ) {
            $lastUsageValue = $record->getUsageValue();
            $dueUsageValue = $lastUsageValue + $everyUsage;

            if (null !== $currentUsage && $currentUsage->unit === $unit) {
                $usageRemaining = $dueUsageValue - $currentUsage->value;
                $usageOverdue = $usageRemaining <= 0;

                $rate = $this->usageRatePerDay($vehicle, $unit);
                if (null !== $rate) {
                    $projectedDueAt = $now->modify(sprintf('%+d days', (int) round($usageRemaining / $rate)));
                }
            }
        }

        // A usage-only rule (no month interval) still gets a day count from its calendar projection,
        // so the soonest-first ordering and the withinDays filter work on the same axis as the rest.
        if (null === $dueAt && null !== $projectedDueAt) {
            $daysUntilDue = (int) $now->diff($projectedDueAt)->format('%r%a');
        }

        return new DueMaintenance(
            type: $type,
            lastPerformedAt: $lastPerformedAt,
            dueAt: $dueAt,
            overdue: $timeOverdue || $usageOverdue,
            daysUntilDue: $daysUntilDue,
            interval: null,
            action: $interval->getAction(),
            component: $interval->getComponent(),
            lastUsageValue: $lastUsageValue,
            dueUsageValue: $dueUsageValue,
            usageRemaining: $usageRemaining,
            usageUnit: $unit,
            projectedDueAt: $projectedDueAt,
            rule: 'schedule',
        );
    }

    private function currentUsage(Vehicle $vehicle): ?CurrentUsage
    {
        $value = $vehicle->getUsageValue();
        $unit = $vehicle->getUsageUnit();
        if (null === $value || null === $unit) {
            return null;
        }

        return new CurrentUsage($value, UsageUnit::fromGarageUnit($unit));
    }

    /**
     * Average usage gained per day, from the earliest to the latest same-unit reading in the history.
     * Needs ≥2 distinct points spanning real time and rising usage; null otherwise (no rate = no
     * calendar projection, only the raw remaining-usage is reported).
     */
    private function usageRatePerDay(Vehicle $vehicle, UsageUnit $unit): ?float
    {
        /** @var list<array{t: \DateTimeImmutable, v: int}> $points */
        $points = [];
        foreach ($vehicle->getMaintenanceRecords() as $record) {
            if (null !== $record->getUsageValue() && $record->getUsageUnit() === $unit) {
                $points[] = ['t' => $record->getPerformedAt(), 'v' => $record->getUsageValue()];
            }
        }

        if (\count($points) < 2) {
            return null;
        }

        usort($points, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);
        $first = $points[0];
        $last = $points[\count($points) - 1];

        $days = (int) $first['t']->diff($last['t'])->format('%a');
        $gained = $last['v'] - $first['v'];
        if ($days <= 0 || $gained <= 0) {
            return null;
        }

        return $gained / $days;
    }
}
