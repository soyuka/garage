<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use PHPUnit\Framework\TestCase;

final class PlannedMaintenancePartsTest extends TestCase
{
    public function testMergePartsAppendsNewRefsAndKeepsExistingOnes(): void
    {
        $task = $this->task([['ref' => 'OC90', 'label' => 'Filtre à huile', 'brand' => 'MANN']]);

        $task->mergeParts([['ref' => '5W30-5L', 'label' => 'Huile moteur', 'qty' => 1]]);

        self::assertSame([
            ['ref' => 'OC90', 'label' => 'Filtre à huile', 'brand' => 'MANN'],
            ['ref' => '5W30-5L', 'label' => 'Huile moteur', 'qty' => 1],
        ], $task->getParts());
    }

    public function testMergePartsUpsertsAPartWithTheSameRefInPlace(): void
    {
        $task = $this->task([['ref' => 'OC90', 'label' => 'Filtre', 'qty' => 1]]);

        $task->mergeParts([['ref' => 'OC90', 'label' => 'Filtre à huile MANN', 'qty' => 2]]);

        self::assertSame([['ref' => 'OC90', 'label' => 'Filtre à huile MANN', 'qty' => 2]], $task->getParts());
    }

    /**
     * @param list<array{ref: string, label?: string, brand?: string, qty?: int}> $parts
     */
    private function task(array $parts): PlannedMaintenance
    {
        return new PlannedMaintenance(
            new Vehicle('Mazda', '6', VehicleType::Car, 'siv'),
            'oil_change',
            'user',
            parts: $parts,
        );
    }
}
