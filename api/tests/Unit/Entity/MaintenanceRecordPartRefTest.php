<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use PHPUnit\Framework\TestCase;

final class MaintenanceRecordPartRefTest extends TestCase
{
    public function testLoggedPartsAreNormalisedAndProductNamesKeptOnTheLabel(): void
    {
        $record = new MaintenanceRecord(
            new Vehicle('Mazda', '6', VehicleType::Car, 'siv'),
            [['type' => 'brakes', 'parts' => [['ref' => 'dot4', 'price' => 8.5], ['ref' => 'Loctite 243']]]],
            new \DateTimeImmutable('2026-01-01'),
            'user',
        );

        $parts = $record->getItems()[0]['parts'];
        $this->assertSame(['ref' => 'DOT 4', 'priceCents' => 850], $parts[0]);
        $this->assertSame(['ref' => 'frein-filet moyen', 'label' => 'Loctite 243'], $parts[1]);
    }
}
