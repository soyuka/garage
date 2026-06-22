<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Maintenance\DueMaintenance;
use App\Maintenance\HealthStatus;
use App\Maintenance\MaintenanceType;
use App\Maintenance\VehicleHealthCalculator;
use App\Maintenance\VehicleMaintenanceForecast;
use PHPUnit\Framework\TestCase;

/**
 * Health is composed on read from the forecast: overdue services pull it red, a service due soon or
 * a missing baseline (gap) makes it amber, a fully-tracked up-to-date vehicle is green, and a
 * vehicle we know nothing about is honestly Unknown — never a false green.
 */
final class VehicleHealthCalculatorTest extends TestCase
{
    public function testNoScheduleNoHistoryIsUnknownNotGreen(): void
    {
        $health = $this->calculator()->forForecast(new VehicleMaintenanceForecast([], []));

        $this->assertSame(HealthStatus::Unknown, $health->status);
        $this->assertNull($health->score, 'Nothing to score on — do not pretend the vehicle is healthy.');
    }

    public function testAllGapsAndNothingTrackedIsUnknownNotAmber(): void
    {
        // A schedule but no logged baseline yet: we know the cadences, not the state. Cannot score.
        $forecast = new VehicleMaintenanceForecast([], [MaintenanceType::OilChange, MaintenanceType::AirFilter]);

        $health = $this->calculator()->forForecast($forecast);

        $this->assertSame(HealthStatus::Unknown, $health->status);
        $this->assertNull($health->score);
        $this->assertSame(2, $health->gaps);
    }

    public function testAnOverdueServiceIsRed(): void
    {
        $forecast = new VehicleMaintenanceForecast([$this->due(MaintenanceType::OilChange, overdue: true, daysUntilDue: -40)], []);

        $health = $this->calculator()->forForecast($forecast);

        $this->assertSame(HealthStatus::Red, $health->status);
        $this->assertSame(1, $health->overdue);
        $this->assertLessThan(100, $health->score);
    }

    public function testEverythingTrackedAndCurrentIsGreenAt100(): void
    {
        $forecast = new VehicleMaintenanceForecast([
            $this->due(MaintenanceType::OilChange, overdue: false, daysUntilDue: 300),
            $this->due(MaintenanceType::AirFilter, overdue: false, daysUntilDue: 400),
        ], []);

        $health = $this->calculator()->forForecast($forecast);

        $this->assertSame(HealthStatus::Green, $health->status);
        $this->assertSame(100, $health->score);
        $this->assertSame(2, $health->tracked);
        $this->assertSame(0, $health->gaps);
    }

    public function testAGapMakesItAmberAndDentsCoverage(): void
    {
        $forecast = new VehicleMaintenanceForecast(
            [$this->due(MaintenanceType::OilChange, overdue: false, daysUntilDue: 300)],
            [MaintenanceType::AirFilter, MaintenanceType::SparkPlug],
        );

        $health = $this->calculator()->forForecast($forecast);

        $this->assertSame(HealthStatus::Amber, $health->status);
        $this->assertSame(2, $health->gaps);
        $this->assertLessThan(100, $health->score);
    }

    public function testADueSoonServiceIsAmberEvenWithoutAGap(): void
    {
        $forecast = new VehicleMaintenanceForecast([$this->due(MaintenanceType::OilChange, overdue: false, daysUntilDue: 10)], []);

        $health = $this->calculator()->forForecast($forecast);

        $this->assertSame(HealthStatus::Amber, $health->status);
        $this->assertSame(1, $health->dueSoon);
    }

    public function testOverdueOutranksGapForStatusButBothCountTowardScore(): void
    {
        $forecast = new VehicleMaintenanceForecast(
            [$this->due(MaintenanceType::OilChange, overdue: true, daysUntilDue: -5)],
            [MaintenanceType::Brakes],
        );

        $health = $this->calculator()->forForecast($forecast);

        $this->assertSame(HealthStatus::Red, $health->status, 'Overdue dominates the status.');
        $this->assertSame(1, $health->overdue);
        $this->assertSame(1, $health->gaps);
    }

    private function calculator(): VehicleHealthCalculator
    {
        return new VehicleHealthCalculator();
    }

    private function due(MaintenanceType $type, bool $overdue, int $daysUntilDue): DueMaintenance
    {
        return new DueMaintenance(
            type: $type,
            lastPerformedAt: new \DateTimeImmutable('2024-01-01'),
            dueAt: new \DateTimeImmutable('2025-01-01'),
            overdue: $overdue,
            daysUntilDue: $daysUntilDue,
        );
    }
}
