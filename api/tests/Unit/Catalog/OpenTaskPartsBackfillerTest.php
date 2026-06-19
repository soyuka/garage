<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\OpenTaskPartsBackfiller;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Repository\PlannedMaintenanceRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\TestCase;

final class OpenTaskPartsBackfillerTest extends TestCase
{
    public function testFillsAnEmptyOpenTaskFromTheMatchingCategoryAndSkipsTheRest(): void
    {
        $vehicle = $this->vehicle();
        $oilFilter = new PlannedMaintenance($vehicle, 'oil_filter', 'agent');
        $airFilter = new PlannedMaintenance($vehicle, 'air_filter', 'agent', parts: [['ref' => 'C 3233', 'brand' => 'MANN-FILTER']]);
        $sparkPlug = new PlannedMaintenance($vehicle, 'spark_plug', 'agent');

        $tasks = $this->createStub(PlannedMaintenanceRepository::class);
        $tasks->method('checklistForVehicle')->willReturn([$oilFilter, $airFilter, $sparkPlug]);

        $em = $this->createMock(DocumentManager::class);
        $em->expects($this->once())->method('flush');

        $categories = [
            ['name' => 'Filtre à huile', 'brands' => [
                ['brand' => 'MANN-FILTER', 'articles' => [['id' => 1, 'number' => 'W 712/73']]],
                ['brand' => 'MAHLE', 'articles' => [['id' => 2, 'number' => 'OC 1182']]],
            ]],
        ];

        $filled = (new OpenTaskPartsBackfiller($tasks, $em))->backfill($vehicle, $categories);

        $this->assertSame(1, $filled, 'only the empty oil_filter task matches the oil-filter category');

        $refs = array_column($oilFilter->getParts(), 'ref');
        $this->assertContains('W 712/73', $refs);
        $this->assertContains('OC 1182', $refs);
        $this->assertSame('Filtre à huile', $oilFilter->getParts()[0]['label'] ?? null, 'the category name labels the part');

        $this->assertSame([['ref' => 'C 3233', 'brand' => 'MANN-FILTER']], $airFilter->getParts(), 'a task that already has parts is never touched');
        $this->assertSame([], $sparkPlug->getParts(), 'a task with no matching category stays empty');
    }

    public function testDoesNotFlushWhenNothingMatches(): void
    {
        $vehicle = $this->vehicle();
        $brakes = new PlannedMaintenance($vehicle, 'brake_pads', 'agent');

        $tasks = $this->createStub(PlannedMaintenanceRepository::class);
        $tasks->method('checklistForVehicle')->willReturn([$brakes]);

        $em = $this->createMock(DocumentManager::class);
        $em->expects($this->never())->method('flush');

        $filled = (new OpenTaskPartsBackfiller($tasks, $em))->backfill($vehicle, [
            ['name' => 'Filtre à huile', 'brands' => [['brand' => 'MANN-FILTER', 'articles' => [['id' => 1, 'number' => 'W 712/73']]]]],
        ]);

        $this->assertSame(0, $filled);
        $this->assertSame([], $brakes->getParts());
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'agent', kType: 12345);
    }
}
