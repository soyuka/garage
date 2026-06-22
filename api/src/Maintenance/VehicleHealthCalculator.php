<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\Vehicle;

/**
 * Composes a vehicle's {@see VehicleHealth} from its forecast — no stored health field, computed on
 * read. The status is severity-ordered (overdue → Red dominates, else a due-soon or a gap → Amber,
 * else Green); the score blends those penalties with coverage (how much of the known schedule has a
 * logged baseline). A vehicle with neither schedule nor history is Unknown, not a false Green.
 */
final readonly class VehicleHealthCalculator
{
    // A service falling due within this window (and not yet overdue) is "due soon" → Amber.
    private const int DUE_SOON_DAYS = 30;

    public function __construct(private DueMaintenanceCalculator $forecaster = new DueMaintenanceCalculator())
    {
    }

    public function forVehicle(Vehicle $vehicle): VehicleHealth
    {
        return $this->forForecast($this->forecaster->forVehicle($vehicle));
    }

    public function forForecast(VehicleMaintenanceForecast $forecast): VehicleHealth
    {
        $tracked = \count($forecast->due);
        $gaps = \count($forecast->unknownBaseline);

        // No tracked service = no baseline to assess (every forecast is a gap, e.g. a fresh vehicle
        // or one with a schedule but no logs yet). Honestly Unknown, never a scored badge.
        if (0 === $tracked) {
            return new VehicleHealth(HealthStatus::Unknown, null, 0, 0, 0, $gaps);
        }

        $overdue = 0;
        $dueSoon = 0;
        foreach ($forecast->due as $due) {
            if ($due->overdue) {
                ++$overdue;
                continue;
            }
            if (null !== $due->daysUntilDue && $due->daysUntilDue >= 0 && $due->daysUntilDue <= self::DUE_SOON_DAYS) {
                ++$dueSoon;
            }
        }

        $gapRatio = $gaps / ($gaps + $tracked);
        $score = max(0, min(100, (int) round(100 - 30 * $overdue - 10 * $dueSoon - 20 * $gapRatio)));

        $status = match (true) {
            $overdue > 0 => HealthStatus::Red,
            $dueSoon > 0 || $gaps > 0 => HealthStatus::Amber,
            default => HealthStatus::Green,
        };

        return new VehicleHealth($status, $score, $overdue, $dueSoon, $tracked, $gaps);
    }
}
