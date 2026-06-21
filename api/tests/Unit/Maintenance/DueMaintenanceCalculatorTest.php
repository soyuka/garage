<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Maintenance\DueMaintenanceCalculator;
use App\Maintenance\MaintenanceType;
use App\Maintenance\UsageUnit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DueMaintenanceCalculatorTest extends TestCase
{
    private const string NOW = '2026-06-15';

    public function testOilChangePastItsIntervalIsOverdue(): void
    {
        $vehicle = $this->vehicle();
        // oil_change interval is 12 months; last done 2 years ago → overdue.
        $this->record($vehicle, MaintenanceType::OilChange, '2024-06-15');

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertCount(1, $due);
        $this->assertSame(MaintenanceType::OilChange, $due[0]->type);
        $this->assertSame('2025-06-15', $due[0]->dueAt->format('Y-m-d'));
        $this->assertTrue($due[0]->overdue);
        $this->assertLessThan(0, $due[0]->daysUntilDue);
    }

    public function testRecentServiceIsNotOverdueAndDueInFuture(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, MaintenanceType::OilChange, '2026-05-15');

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertCount(1, $due);
        $this->assertFalse($due[0]->overdue);
        $this->assertSame('2027-05-15', $due[0]->dueAt->format('Y-m-d'));
        $this->assertGreaterThan(0, $due[0]->daysUntilDue);
    }

    public function testNeverServicedRecurringTypeIsReportedAsUnknownBaselineNotDropped(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, MaintenanceType::OilChange, '2026-05-15');

        $forecast = $this->calculator()->forVehicle($vehicle);

        $dueTypes = array_map(static fn ($d): MaintenanceType => $d->type, $forecast->due);
        $this->assertNotContains(MaintenanceType::AirFilter, $dueTypes, 'No baseline → cannot forecast a due date.');
        $this->assertContains(MaintenanceType::AirFilter, $forecast->unknownBaseline, 'The gap must be surfaced, not silently skipped.');
        $this->assertNotContains(MaintenanceType::OilChange, $forecast->unknownBaseline, 'A serviced type is not an unknown baseline.');
    }

    public function testOneVisitWithMultipleItemsForecastsEachItemType(): void
    {
        // A single service visit changed oil AND the oil filter on the same day. One record, two
        // items — each item's type must seed its own forecast (the visit is not collapsed to one type).
        $vehicle = $this->vehicle();
        new MaintenanceRecord(
            $vehicle,
            [
                ['type' => MaintenanceType::OilChange->value, 'parts' => []],
                ['type' => MaintenanceType::OilFilter->value, 'parts' => []],
            ],
            new \DateTimeImmutable('2026-05-15'),
            'user',
        );

        $forecast = $this->calculator()->forVehicle($vehicle);
        $dueTypes = array_map(static fn ($d): MaintenanceType => $d->type, $forecast->due);

        $this->assertContains(MaintenanceType::OilChange, $dueTypes);
        $this->assertContains(MaintenanceType::OilFilter, $dueTypes);
        $this->assertNotContains(MaintenanceType::OilFilter, $forecast->unknownBaseline, 'An item logged in the visit is a real baseline, not a gap.');
    }

    public function testNonRecurringTypeIsNeitherForecastNorAnUnknownBaseline(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, MaintenanceType::Repair, '2024-01-01');

        $forecast = $this->calculator()->forVehicle($vehicle);

        $this->assertSame([], $forecast->due, 'repair is not recurring — no interval, no forecast.');
        $this->assertNotContains(MaintenanceType::Repair, $forecast->unknownBaseline, 'A non-recurring type is not a missing baseline.');
        $this->assertNotContains(MaintenanceType::Other, $forecast->unknownBaseline);
    }

    public function testUsageIntervalIsExposedSoTheCallerCanAskForCurrentReading(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, MaintenanceType::OilChange, '2026-05-15');

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertSame(10000, $due[0]->interval->usageValue);
        $this->assertSame(UsageUnit::Kilometers, $due[0]->interval->usageUnit);
    }

    public function testLatestRecordOfATypeWins(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, MaintenanceType::OilChange, '2022-01-01');
        $this->record($vehicle, MaintenanceType::OilChange, '2026-01-01');

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertCount(1, $due);
        $this->assertSame('2027-01-01', $due[0]->dueAt->format('Y-m-d'), 'Due date must derive from the most recent service.');
    }

    public function testDueResultsAreSortedByDueDateAscending(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, MaintenanceType::OilChange, '2026-05-15');   // due 2027-05-15
        $this->record($vehicle, MaintenanceType::Inspection, '2025-01-01');  // 24mo → due 2027-01-01 (sooner)

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertCount(2, $due);
        $this->assertSame(MaintenanceType::Inspection, $due[0]->type, 'Soonest due first.');
        $this->assertSame(MaintenanceType::OilChange, $due[1]->type);
    }

    private function calculator(): DueMaintenanceCalculator
    {
        return new DueMaintenanceCalculator(new MockClock(self::NOW));
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle('TORO', '246-H', VehicleType::GroundsCare, 'llm');
    }

    private function record(Vehicle $vehicle, MaintenanceType $type, string $performedAt): void
    {
        new MaintenanceRecord($vehicle, [['type' => $type->value, 'parts' => []]], new \DateTimeImmutable($performedAt), 'llm');
    }
}
