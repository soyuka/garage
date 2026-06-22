<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ServiceInterval;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\UsageUnit;
use PHPUnit\Framework\TestCase;

final class VehicleServiceScheduleTest extends TestCase
{
    public function testNewVehicleHasEmptySchedule(): void
    {
        $this->assertSame([], $this->vehicle()->serviceSchedule());
    }

    public function testSetServiceIntervalUpsertsByKey(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->setServiceInterval(new ServiceInterval('oil_change', MaintenanceAction::Change, 'web', everyUsageValue: 50, everyUsageUnit: UsageUnit::Hours));
        // Same key (oil_change:change) → replaces in place, never stacks a duplicate.
        $vehicle->setServiceInterval(new ServiceInterval('oil_change', MaintenanceAction::Change, 'web', everyUsageValue: 60, everyUsageUnit: UsageUnit::Hours));

        $schedule = $vehicle->serviceSchedule();
        $this->assertCount(1, $schedule);
        $this->assertSame(60, $schedule[0]->getEveryUsageValue());
    }

    public function testTwoCadencesOfOneTypeCoexist(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->setServiceInterval(new ServiceInterval('spark_plug', MaintenanceAction::Inspect, 'web', everyUsageValue: 25, everyUsageUnit: UsageUnit::Hours));
        $vehicle->setServiceInterval(new ServiceInterval('spark_plug', MaintenanceAction::Replace, 'web', everyUsageValue: 100, everyUsageUnit: UsageUnit::Hours));

        $this->assertCount(2, $vehicle->serviceSchedule(), 'inspect@25h and replace@100h are distinct rules.');
    }

    public function testDeletedIntervalLeavesTheLiveSchedule(): void
    {
        $vehicle = $this->vehicle();
        $oil = new ServiceInterval('oil_change', MaintenanceAction::Change, 'web', everyUsageValue: 50, everyUsageUnit: UsageUnit::Hours);
        $vehicle->setServiceInterval($oil);

        $oil->delete(new \DateTimeImmutable('2026-06-22'));

        $this->assertSame([], $vehicle->serviceSchedule(), 'A tombstoned rule is hidden from the live schedule.');
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle('TORO', '246-H', VehicleType::GroundsCare, 'llm');
    }
}
