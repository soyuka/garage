<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * A forecast service for one (vehicle, type): when it was last done, when it is next due on the
 * time axis, and whether that date has passed. usageInterval is advisory display only.
 */
final readonly class DueMaintenance
{
    public function __construct(
        public MaintenanceType $type,
        public \DateTimeImmutable $lastPerformedAt,
        public \DateTimeImmutable $dueAt,
        public bool $overdue,
        public int $daysUntilDue,
        public MaintenanceInterval $interval,
    ) {
    }
}
