<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Entity\ServiceInterval;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Maintenance\DueMaintenanceCalculator;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\MaintenanceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The time axis of the schedule-only forecast: a per-vehicle {@see ServiceInterval} with a month
 * cadence, anchored on the last logged service. No generic general-knowledge defaults exist — a type
 * is forecast only when the vehicle carries a rule for it (see {@see UsageDrivenForecastTest} for the
 * usage axis).
 */
final class DueMaintenanceCalculatorTest extends TestCase
{
    private const string NOW = '2026-06-15';

    public function testScheduledServicePastItsMonthIntervalIsOverdue(): void
    {
        $vehicle = $this->vehicle();
        $this->scheduleMonths($vehicle, 'oil_change', 12);
        // Last done 2 years ago against a 12-month cadence → overdue.
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
        $this->scheduleMonths($vehicle, 'oil_change', 12);
        $this->record($vehicle, MaintenanceType::OilChange, '2026-05-15');

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertCount(1, $due);
        $this->assertFalse($due[0]->overdue);
        $this->assertSame('2027-05-15', $due[0]->dueAt->format('Y-m-d'));
        $this->assertGreaterThan(0, $due[0]->daysUntilDue);
    }

    public function testScheduledTypeWithNoMatchingRecordIsAnUnknownBaselineNotDropped(): void
    {
        $vehicle = $this->vehicle();
        $this->scheduleMonths($vehicle, 'oil_change', 12);
        $this->scheduleMonths($vehicle, 'air_filter', 24, MaintenanceAction::Replace);
        // Only the oil change is logged; the air filter is scheduled but never recorded.
        $this->record($vehicle, MaintenanceType::OilChange, '2026-05-15');

        $forecast = $this->calculator()->forVehicle($vehicle);

        $dueTypes = array_map(static fn ($d): MaintenanceType => $d->type, $forecast->due);
        $this->assertNotContains(MaintenanceType::AirFilter, $dueTypes, 'No baseline → cannot forecast a due date.');
        $this->assertContains(MaintenanceType::AirFilter, $forecast->unknownBaseline, 'The gap must be surfaced, not silently skipped.');
        $this->assertNotContains(MaintenanceType::OilChange, $forecast->unknownBaseline, 'A serviced type is not an unknown baseline.');
    }

    public function testATypeWithNoScheduleRuleIsNeitherForecastNorAGap(): void
    {
        // The whole point of schedule-only: a logged type the user never scheduled stays silent —
        // no invented forecast, no nagging gap.
        $vehicle = $this->vehicle();
        $this->record($vehicle, MaintenanceType::OilChange, '2026-05-15');

        $forecast = $this->calculator()->forVehicle($vehicle);

        $this->assertSame([], $forecast->due, 'No rule for oil_change → no forecast despite a logged service.');
        $this->assertSame([], $forecast->unknownBaseline, 'An unscheduled type is not a gap.');
    }

    public function testOneVisitWithMultipleItemsForecastsEachScheduledType(): void
    {
        // A single visit changed oil AND the oil filter; both are scheduled, so each seeds its own due.
        $vehicle = $this->vehicle();
        $this->scheduleMonths($vehicle, 'oil_change', 12);
        $this->scheduleMonths($vehicle, 'oil_filter', 24, MaintenanceAction::Replace);
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
        $this->assertSame([], $forecast->unknownBaseline, 'Both scheduled types were logged — no gaps.');
    }

    public function testLatestRecordOfATypeWins(): void
    {
        $vehicle = $this->vehicle();
        $this->scheduleMonths($vehicle, 'oil_change', 12);
        $this->record($vehicle, MaintenanceType::OilChange, '2022-01-01');
        $this->record($vehicle, MaintenanceType::OilChange, '2026-01-01');

        $due = $this->calculator()->forVehicle($vehicle)->due;

        $this->assertCount(1, $due);
        $this->assertSame('2027-01-01', $due[0]->dueAt->format('Y-m-d'), 'Due date must derive from the most recent service.');
    }

    public function testDueResultsAreSortedByDueDateAscending(): void
    {
        $vehicle = $this->vehicle();
        $this->scheduleMonths($vehicle, 'oil_change', 12);
        $this->scheduleMonths($vehicle, 'inspection', 24, MaintenanceAction::Inspect);
        $this->record($vehicle, MaintenanceType::OilChange, '2026-05-15');   // due 2027-05-15
        $this->record($vehicle, MaintenanceType::Inspection, '2025-01-01');  // due 2027-01-01 (sooner)

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

    private function scheduleMonths(Vehicle $vehicle, string $type, int $months, MaintenanceAction $action = MaintenanceAction::Change): void
    {
        $vehicle->setServiceInterval(ServiceInterval::fromSource($type, $action, 'web', everyMonths: $months));
    }

    private function record(Vehicle $vehicle, MaintenanceType $type, string $performedAt): void
    {
        new MaintenanceRecord($vehicle, [['type' => $type->value, 'parts' => []]], new \DateTimeImmutable($performedAt), 'llm');
    }
}
