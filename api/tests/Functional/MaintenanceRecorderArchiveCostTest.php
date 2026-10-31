<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceRecorder;
use App\Maintenance\PlannedMaintenanceStatus;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Rider 2: archiveCompletedTasks() takes an OPTIONAL bill total. Every current caller (the
 * ArchiveCompletedTasksProcessor) still passes none — the archived record then falls back to its
 * parts-derived estimate via getEffectiveCostCents() — but a caller that DOES have a bill in hand can
 * thread it straight onto the filed record.
 */
final class MaintenanceRecorderArchiveCostTest extends AppTestCase
{
    private function seedVehicleWithDoneTask(): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);

        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);

        $task = new PlannedMaintenance(vehicle: $vehicle, type: 'oil_change', source: 'user', owner: self::TEST_OWNER);
        $task->close(PlannedMaintenanceStatus::Done, new \DateTimeImmutable());
        $dm->persist($task);

        $dm->flush();

        return $vehicle;
    }

    public function testArchiveWithNoCostCentsLeavesTheRecordEstimateOnly(): void
    {
        $vehicle = $this->seedVehicleWithDoneTask();
        $recorder = static::getContainer()->get(MaintenanceRecorder::class);

        $records = $recorder->archiveCompletedTasks($vehicle, new \DateTimeImmutable());

        self::assertCount(1, $records);
        self::assertNull($records[0]->getCostCents());
    }

    public function testArchiveWithCostCentsStampsTheRecordWithTheBill(): void
    {
        $vehicle = $this->seedVehicleWithDoneTask();
        $recorder = static::getContainer()->get(MaintenanceRecorder::class);

        $records = $recorder->archiveCompletedTasks($vehicle, new \DateTimeImmutable(), costCents: 7500);

        self::assertCount(1, $records);
        self::assertSame(7500, $records[0]->getCostCents());
        self::assertSame(7500, $records[0]->getEffectiveCostCents());
        self::assertSame('bill', $records[0]->getCostSource());
    }
}
