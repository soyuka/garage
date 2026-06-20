<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Garage\UsageUnit as GarageUsageUnit;

/**
 * Unit of the usage reading captured at service time. `hours` is non-negotiable: groundscare
 * machinery (the TORO 246-H) meters engine hours, not distance — see the root TODO.md.
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

    /**
     * The garage-side counterpart, mapped by case (NOT value): this enum spells engine hours
     * `hours` while {@see GarageUsageUnit} spells it `h`, so a reading promoted onto a vehicle's
     * own usage must cross the two enums by name, never by their backing string.
     */
    public function toGarageUnit(): GarageUsageUnit
    {
        return match ($this) {
            self::Kilometers => GarageUsageUnit::Kilometers,
            self::Miles => GarageUsageUnit::Miles,
            self::Hours => GarageUsageUnit::Hours,
        };
    }

    /**
     * The inverse of {@see toGarageUnit()}: lift a vehicle's garage-side reading (where engine hours
     * are spelled `h`) into this log enum (`hours`). Crossed by case, never by backing string.
     */
    public static function fromGarageUnit(GarageUsageUnit $unit): self
    {
        return match ($unit) {
            GarageUsageUnit::Kilometers => self::Kilometers,
            GarageUsageUnit::Miles => self::Miles,
            GarageUsageUnit::Hours => self::Hours,
        };
    }
}
