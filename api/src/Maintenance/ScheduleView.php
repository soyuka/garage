<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\ServiceInterval;

/**
 * Flattens the recurring schedule into the rows the set_maintenance_schedule tool echoes back, so
 * the agent sees the effect of its write. Each row is one {@see ServiceInterval} with its two
 * cadences (usage + time), the break-in override, and full provenance.
 */
final class ScheduleView
{
    /**
     * @param list<ServiceInterval> $intervals
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(array $intervals): array
    {
        return array_map(static fn (ServiceInterval $i): array => [
            'type' => $i->getType(),
            'action' => $i->getAction()->value,
            'component' => $i->getComponent(),
            'everyUsage' => null === $i->getEveryUsageValue() ? null : [
                'value' => $i->getEveryUsageValue(),
                'unit' => $i->getEveryUsageUnit()?->value,
            ],
            'everyMonths' => $i->getEveryMonths(),
            'firstUsageValue' => $i->getFirstUsageValue(),
            'source' => $i->getSource(),
            'verified' => $i->isVerified(),
            'url' => $i->getUrl(),
        ], $intervals);
    }
}
