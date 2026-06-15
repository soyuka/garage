<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * Unit of the usage reading captured at service time. `hours` is non-negotiable: groundscare
 * machinery (the TORO 246-H) meters engine hours, not distance — see docs/TODO.md.
 */
enum UsageUnit: string
{
    case Kilometers = 'km';
    case Miles = 'mi';
    case Hours = 'hours';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
