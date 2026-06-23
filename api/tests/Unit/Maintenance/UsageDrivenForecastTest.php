<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Entity\ServiceInterval;
use App\Entity\Vehicle;
use App\Garage\UsageUnit as GarageUsageUnit;
use App\Garage\VehicleType;
use App\Maintenance\DueMaintenanceCalculator;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\MaintenanceType;
use App\Maintenance\UsageUnit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The schedule layer driving the forecast: a per-vehicle {@see ServiceInterval} overrides the
 * generic time defaults, adds a usage axis (engine hours for the TORO), and — with enough history —
 * a usage-rate projection onto the calendar.
 */
final class UsageDrivenForecastTest extends TestCase
{
    private const string NOW = '2026-06-15';

    public function testPerVehicleScheduleComputesUsageDueFromLastRecordAndCurrentReading(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->setUsage(190, GarageUsageUnit::Hours);
        $vehicle->setServiceInterval(ServiceInterval::fromSource(
            'oil_change', MaintenanceAction::Change, 'web',
            everyUsageValue: 50, everyUsageUnit: UsageUnit::Hours,
        ));
        $this->recordWithUsage($vehicle, MaintenanceType::OilChange, '2026-01-01', 150);

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertCount(1, $due);
        $this->assertSame(MaintenanceType::OilChange, $due[0]->type);
        $this->assertSame(MaintenanceAction::Change, $due[0]->action);
        $this->assertSame(200, $due[0]->dueUsageValue, 'lastUsage 150 + 50h cadence.');
        $this->assertSame(10, $due[0]->usageRemaining, 'due 200 − current 190.');
        $this->assertSame(UsageUnit::Hours, $due[0]->usageUnit);
    }

    public function testEachScheduleRuleForecastsExactlyOnce(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->setUsage(190, GarageUsageUnit::Hours);
        $vehicle->setServiceInterval(ServiceInterval::fromSource(
            'oil_change', MaintenanceAction::Change, 'web',
            everyUsageValue: 50, everyUsageUnit: UsageUnit::Hours,
        ));
        $this->recordWithUsage($vehicle, MaintenanceType::OilChange, '2026-01-01', 150);

        $oil = array_values(array_filter(
            $this->calculator()->forVehicle($vehicle)->due,
            static fn ($d): bool => MaintenanceType::OilChange === $d->type,
        ));

        // The schedule is the sole source — one rule, one forecast (no generic default double-counts).
        $this->assertCount(1, $oil);
        $this->assertSame('schedule', $oil[0]->rule);
    }

    public function testScheduleRuleWithNoMatchingRecordIsAnHonestGapNotAFalseForecast(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->setUsage(190, GarageUsageUnit::Hours);
        $vehicle->setServiceInterval(ServiceInterval::fromSource(
            'air_filter', MaintenanceAction::Replace, 'web',
            everyUsageValue: 100, everyUsageUnit: UsageUnit::Hours, component: 'paper cartridge',
        ));

        $forecast = $this->calculator()->forVehicle($vehicle);

        $dueTypes = array_map(static fn ($d): MaintenanceType => $d->type, $forecast->due);
        $this->assertNotContains(MaintenanceType::AirFilter, $dueTypes, 'No logged baseline → no forecast.');
        $this->assertContains(MaintenanceType::AirFilter, $forecast->unknownBaseline, 'We know the cadence, just not the baseline — surface the gap.');
    }

    public function testUsageRateFromHistoryProjectsADueDateOntoTheCalendar(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->setUsage(200, GarageUsageUnit::Hours);
        $vehicle->setServiceInterval(ServiceInterval::fromSource(
            'oil_change', MaintenanceAction::Change, 'web',
            everyUsageValue: 50, everyUsageUnit: UsageUnit::Hours,
        ));
        // Two readings a year apart, 100 h gained → ~100 h/year. due is 50 h out ≈ half a year.
        $this->recordWithUsage($vehicle, MaintenanceType::OilChange, '2025-01-01', 100);
        $this->recordWithUsage($vehicle, MaintenanceType::OilChange, '2026-01-01', 200);

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertCount(1, $due);
        $this->assertSame(250, $due[0]->dueUsageValue);
        $this->assertNotNull($due[0]->projectedDueAt, 'A usage rate lets us project a date even on the hours axis.');
        $this->assertGreaterThan(new \DateTimeImmutable(self::NOW), $due[0]->projectedDueAt);
    }

    public function testWhicheverFirstExposesBothAxesAndIsOverdueIfEitherBreached(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->setUsage(300, GarageUsageUnit::Hours);
        $vehicle->setServiceInterval(ServiceInterval::fromSource(
            'oil_change', MaintenanceAction::Change, 'web',
            everyUsageValue: 50, everyUsageUnit: UsageUnit::Hours, everyMonths: 12,
        ));
        // Time axis fine (done last month) but usage blew past: 250 + 50 = 300 due, already at 300.
        $this->recordWithUsage($vehicle, MaintenanceType::OilChange, '2026-05-15', 250);

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertSame(0, $due[0]->usageRemaining, 'due 300 − current 300.');
        $this->assertTrue($due[0]->overdue, 'Usage breached even though the time axis is fresh — whichever first.');
        $this->assertNotNull($due[0]->dueAt, 'Time axis still yields a calendar date.');
    }

    private function calculator(): DueMaintenanceCalculator
    {
        return new DueMaintenanceCalculator(new MockClock(self::NOW));
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle('TORO', '246-H', VehicleType::GroundsCare, 'llm');
    }

    private function recordWithUsage(Vehicle $vehicle, MaintenanceType $type, string $performedAt, int $usage): void
    {
        new MaintenanceRecord(
            $vehicle,
            [['type' => $type->value, 'parts' => []]],
            new \DateTimeImmutable($performedAt),
            'llm',
            usageValue: $usage,
            usageUnit: UsageUnit::Hours,
        );
    }
}
