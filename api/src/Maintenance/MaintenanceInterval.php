<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * The recurrence of a service type: a time interval (the axis we forecast on) plus an optional
 * usage interval kept for display only — we store past odometer/hour readings, never a live
 * one, so "due in X km" is unknowable and must not drive the schedule (see docs/TODO.md).
 *
 * The values are GENERAL-KNOWLEDGE DEFAULTS, not manufacturer-authoritative, and deliberately
 * conservative; a per-vehicle override belongs on the future DB-backed schedule, not here.
 * Non-recurring types (repair, other) have no interval — {@see forType()} returns null.
 */
final readonly class MaintenanceInterval
{
    public function __construct(
        public int $months,
        public ?int $usageValue = null,
        public ?UsageUnit $usageUnit = null,
    ) {
    }

    public static function forType(MaintenanceType $type): ?self
    {
        return match ($type) {
            MaintenanceType::OilChange => new self(12, 10000, UsageUnit::Kilometers),
            MaintenanceType::OilFilter => new self(12, 10000, UsageUnit::Kilometers),
            MaintenanceType::CabinFilter => new self(12),
            MaintenanceType::AirFilter => new self(24, 30000, UsageUnit::Kilometers),
            MaintenanceType::FuelFilter => new self(24, 30000, UsageUnit::Kilometers),
            MaintenanceType::Brakes => new self(24),
            MaintenanceType::Inspection => new self(24),
            MaintenanceType::SparkPlug => new self(48, 60000, UsageUnit::Kilometers),
            MaintenanceType::Coolant => new self(48),
            MaintenanceType::Battery => new self(48),
            MaintenanceType::Tires => new self(60),
            MaintenanceType::Repair, MaintenanceType::Other => null,
        };
    }
}
