<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * The kind of service event logged against a vehicle. Common cases are enumerated so the
 * future record-card UI can group + icon them; `Other` is the escape hatch for the long tail
 * (e.g. groundscare blade-sharpening) — pair it with a free-text label on the record.
 */
enum MaintenanceType: string
{
    case OilChange = 'oil_change';
    case OilFilter = 'oil_filter';
    case AirFilter = 'air_filter';
    case FuelFilter = 'fuel_filter';
    case CabinFilter = 'cabin_filter';
    case SparkPlug = 'spark_plug';
    case Tires = 'tires';
    case Brakes = 'brakes';
    case Coolant = 'coolant';
    case Battery = 'battery';
    case Inspection = 'inspection';
    case Repair = 'repair';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
