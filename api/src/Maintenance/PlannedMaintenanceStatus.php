<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * Lifecycle of a planned (not-yet-done) maintenance task: the checklist holds the `Open` ones;
 * `Done` is reached by ticking it off or by logging the matching service (auto-close); `Dismissed`
 * drops it without ever doing the work. Distinct from {@see \App\Entity\MaintenanceRecord}, which is
 * the append-only log of work actually performed — a planned task is intent, a record is history.
 */
enum PlannedMaintenanceStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Dismissed = 'dismissed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
