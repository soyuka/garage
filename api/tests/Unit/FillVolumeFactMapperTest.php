<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ApiResource\FillVolume;
use App\Garage\FillVolumeFactMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FillVolumeFactMapperTest extends TestCase
{
    public function testNumericVolumeIsTrimmedToCanonicalKey(): void
    {
        $facts = (new FillVolumeFactMapper())->toFacts([
            new FillVolume(0, 'Huile moteur', 'Моторное масло с фильтром', 5.50, 'liter', null),
        ]);

        $this->assertSame(
            [['key' => 'oil_capacity', 'value' => '5.5', 'unit' => 'liter']],
            $facts,
        );
    }

    public function testIntegralVolumeDropsTrailingZerosAndPoint(): void
    {
        $facts = (new FillVolumeFactMapper())->toFacts([
            new FillVolume(0, 'Liquide de refroidissement', 'Охлаждающая жидкость', 9.0, 'liter', null),
        ]);

        $this->assertSame('9', $facts[0]['value']);
    }

    public function testSpecificationRowFallsBackToInfoString(): void
    {
        $facts = (new FillVolumeFactMapper())->toFacts([
            new FillVolume(0, 'Spéc. huile moteur', 'Спецификация моторного масла', null, null, 'ACEA E3|ACEA E4|ACEA E5'),
        ]);

        $this->assertSame(
            [['key' => 'oil_type', 'value' => 'ACEA E3|ACEA E4|ACEA E5', 'unit' => null]],
            $facts,
        );
    }

    public function testRowWithNeitherVolumeNorInfoIsDropped(): void
    {
        $facts = (new FillVolumeFactMapper())->toFacts([
            new FillVolume(0, 'Spéc. huile moteur', 'Спецификация моторного масла', null, null, null),
        ]);

        $this->assertSame([], $facts);
    }

    public function testUnmappedTitleIsDropped(): void
    {
        $facts = (new FillVolumeFactMapper())->toFacts([
            new FillVolume(0, 'Mystery', 'Совершенно неизвестный заголовок', 1.0, 'liter', null),
        ]);

        $this->assertSame([], $facts);
    }

    /**
     * AUTONORMS lists these fluids for every transmission/trim variant of a kType (a car may carry
     * both manual gearbox AND automatic gearbox rows). All are mapped to canonical keys — coexistence
     * is accepted upstream of the store.
     *
     */
    #[DataProvider('extendedFluidProvider')]
    public function testExtendedFluidTitlesMapToCanonicalKeys(FillVolume $row, array $expected): void
    {
        $this->assertSame([$expected], (new FillVolumeFactMapper())->toFacts([$row]));
    }

    /**
     * @return iterable<string, array{FillVolume, array{key: string, value: string, unit: string|null}}>
     */
    public static function extendedFluidProvider(): iterable
    {
        yield 'auto gearbox oil capacity' => [
            new FillVolume(0, 'Huile boîte auto', 'Масло для автоматической коробки переключения передач', 8.14, 'liter', null),
            ['key' => 'auto_gearbox_oil_capacity', 'value' => '8.14', 'unit' => 'liter'],
        ];
        yield 'auto gearbox oil spec' => [
            new FillVolume(0, 'Spéc. boîte auto', 'Спецификация масла автоматической коробки передач', null, null, 'ATF Dexron III'),
            ['key' => 'auto_gearbox_oil_type', 'value' => 'ATF Dexron III', 'unit' => null],
        ];
        yield 'clutch fluid spec' => [
            new FillVolume(0, 'Spéc. liquide embrayage', 'Спецификация жидкости сцепления', null, null, 'DOT 4'),
            ['key' => 'clutch_fluid_type', 'value' => 'DOT 4', 'unit' => null],
        ];
        yield 'a/c oil capacity' => [
            new FillVolume(0, 'Huile climatisation', 'Масло кондиционер', 150.0, 'cm³', null),
            ['key' => 'ac_oil_capacity', 'value' => '150', 'unit' => 'cm³'],
        ];
        yield 'fuel tank capacity' => [
            new FillVolume(0, 'Réservoir carburant', 'Ёмкость бака', 64.0, 'liter', null),
            ['key' => 'fuel_tank_capacity', 'value' => '64', 'unit' => 'liter'],
        ];
    }

    public function testRepeatedTitleCollapsesToOneFactWithMergedValues(): void
    {
        // GetFillVolumes lists multiple specs under one title (e.g. "API GL4" + "ZF TE-ML 02D"
        // for the gearbox oil). They must collapse to a single canonical-key fact — one fact per
        // key (UNIQUE(vehicle_id, fact_key)), or the write-through violates the constraint.
        $title = 'Спецификация масла ступенчатой коробки передач';
        $facts = (new FillVolumeFactMapper())->toFacts([
            new FillVolume(0, 'Spéc. boîte', $title, null, null, 'API GL4'),
            new FillVolume(1, 'Spéc. boîte', $title, null, null, 'ZF TE-ML 02D'),
        ]);

        $this->assertSame(
            [['key' => 'gearbox_oil_type', 'value' => 'API GL4 | ZF TE-ML 02D', 'unit' => null]],
            $facts,
        );
    }
}
