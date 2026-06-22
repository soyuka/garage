<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * What a scheduled service DOES to a component — the axis a single {@see MaintenanceType} cannot
 * carry alone. A spark plug is INSPECTED every 25 h but REPLACED every 100 h; the air filter's foam
 * pre-filter is CLEANED while its paper cartridge is REPLACED. The action lets one type hold several
 * independent cadences on a {@see \App\Entity\ServiceInterval} (paired with an optional component).
 */
enum MaintenanceAction: string
{
    case Change = 'change';
    case Replace = 'replace';
    case Clean = 'clean';
    case Inspect = 'inspect';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
