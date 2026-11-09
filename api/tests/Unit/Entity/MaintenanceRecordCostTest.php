<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use PHPUnit\Framework\TestCase;

/**
 * The derived "effective cost" (Option A): a visit's bill total (costCents) wins when present;
 * otherwise the sum of its parts' unit prices × qty stands in as an estimate. Dashboard/agent both
 * read this single server-computed value instead of re-deriving it client-side.
 *
 * @phpstan-import-type ItemWire from MaintenanceRecord
 */
final class MaintenanceRecordCostTest extends TestCase
{
    /**
     * @param list<ItemWire> $items
     */
    private function record(array $items, ?int $costCents = null): MaintenanceRecord
    {
        $vehicle = new Vehicle('Mazda', '6', VehicleType::Car, 'siv');

        return new MaintenanceRecord(
            vehicle: $vehicle,
            items: $items,
            performedAt: new \DateTimeImmutable('2025-09-12'),
            source: 'user',
            costCents: $costCents,
        );
    }

    public function testBillCostWinsOverPartsWhenPresent(): void
    {
        $record = $this->record(
            items: [['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'priceCents' => 1250, 'qty' => 5]]]],
            costCents: 8900,
        );

        $this->assertSame(8900, $record->getEffectiveCostCents());
        $this->assertSame('bill', $record->getCostSource());
    }

    public function testEstimatedFromPartsWhenNoBillCost(): void
    {
        $record = $this->record(items: [
            ['type' => 'oil_change', 'parts' => [
                ['ref' => '5W30', 'priceCents' => 1250, 'qty' => 5],
                ['ref' => 'W712/52', 'priceCents' => 890],
            ]],
        ]);

        // 1250 * 5 + 890 * 1 (qty defaults to 1)
        $this->assertSame(7140, $record->getEffectiveCostCents());
        $this->assertSame('estimated', $record->getCostSource());
    }

    public function testSumsPartsAcrossMultipleItems(): void
    {
        $record = $this->record(items: [
            ['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'priceCents' => 1000, 'qty' => 2]]],
            ['type' => 'brakes', 'parts' => [['ref' => 'BRK-1', 'priceCents' => 3000]]],
        ]);

        $this->assertSame(5000, $record->getEffectiveCostCents());
        $this->assertSame('estimated', $record->getCostSource());
    }

    public function testNullWhenNoBillAndNoPricedParts(): void
    {
        $record = $this->record(items: [
            ['type' => 'inspection', 'parts' => []],
            ['type' => 'oil_change', 'parts' => [['ref' => '5W30']]],
        ]);

        $this->assertNull($record->getEffectiveCostCents());
        $this->assertNull($record->getCostSource());
    }

    public function testPartsWithoutPriceContributeZero(): void
    {
        $record = $this->record(items: [
            ['type' => 'oil_change', 'parts' => [
                ['ref' => '5W30', 'priceCents' => 500],
                ['ref' => 'no-price'],
            ]],
        ]);

        $this->assertSame(500, $record->getEffectiveCostCents());
        $this->assertSame('estimated', $record->getCostSource());
    }
}
