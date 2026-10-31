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
        ], array_map(self::withoutId(...), $task->getParts()));
        foreach ($task->getParts() as $part) {
            self::assertNotSame('', $part['id'], 'every stored line carries a ULID');
        }
    }

    public function testMergePartsUpsertsAPartWithTheSameRefInPlace(): void
    {
        $task = $this->task([['ref' => 'OC90', 'label' => 'Filtre', 'qty' => 1]]);
        $idBefore = $task->getParts()[0]['id'];

        $task->mergeParts([['ref' => 'OC90', 'label' => 'Filtre à huile MANN', 'qty' => 2]]);

        $parts = $task->getParts();
        self::assertCount(1, $parts);
        self::assertSame($idBefore, $parts[0]['id'], 'an in-place upsert keeps the line identity');
        self::assertSame(['ref' => 'OC90', 'label' => 'Filtre à huile MANN', 'qty' => 2], self::withoutId($parts[0]));
    }

    public function testRefLessPartsWithDistinctLabelsStayDistinctLines(): void
    {
        $task = $this->task([]);

        $task->mergeParts([
            ['ref' => '', 'label' => 'Huile boîte 75W-80'],
            ['ref' => '', 'label' => 'Liquide de frein DOT 4'],
            ['label' => 'Loctite Frenbloc'],
        ]);

        self::assertSame(
            ['Huile boîte 75W-80', 'Liquide de frein DOT 4', 'Loctite Frenbloc'],
            array_column($task->getParts(), 'label'),
        );
    }

    public function testRefLessPartMatchesAnExistingOneByNormalisedLabel(): void
    {
        $task = $this->task([['ref' => '', 'label' => 'Liquide de frein DOT 4', 'qty' => 1]]);
        $idBefore = $task->getParts()[0]['id'];

        $task->mergeParts([['ref' => '', 'label' => '  liquide DE FREIN dot 4 ', 'qty' => 2]]);

        $parts = $task->getParts();
        self::assertCount(1, $parts);
        self::assertSame($idBefore, $parts[0]['id']);
        self::assertSame(2, $parts[0]['qty']);
    }

    public function testRefLessPartsWithoutLabelCollapseIntoOneBareFluidLine(): void
    {
        $task = $this->task([['ref' => '', 'qty' => 1]]);

        $task->mergeParts([['ref' => '', 'qty' => 5]]);

        self::assertCount(1, $task->getParts());
        self::assertSame(5, $task->getParts()[0]['qty']);
    }

    public function testRefLessPartNeverMatchesARefedLine(): void
    {
        $task = $this->task([['ref' => 'OC90', 'label' => 'Filtre à huile']]);

        $task->mergeParts([['ref' => '', 'label' => 'Filtre à huile']]);

        self::assertCount(2, $task->getParts());
    }

    public function testAddPartAppliesTheSameIdentityRule(): void
    {
        $task = $this->task([['ref' => '', 'label' => 'Huile boîte']]);

        $task->addPart(['ref' => '', 'label' => 'Liquide de frein']);
        $task->addPart(['ref' => '', 'label' => 'LIQUIDE de frein', 'qty' => 2]);

        self::assertCount(2, $task->getParts());
    }

    public function testConstructorDedupesTheIncomingBatchWithTheSameRule(): void
    {
        $task = $this->task([
            ['ref' => '', 'label' => 'Huile boîte'],
            ['ref' => '', 'label' => 'Liquide de frein'],
            ['ref' => '', 'label' => 'huile boîte', 'qty' => 2],
        ]);

        self::assertCount(2, $task->getParts());
    }

    /**
     * @param array<string, mixed> $part
     *
     * @return array<string, mixed>
     */
    private static function withoutId(array $part): array
    {
        unset($part['id']);

        return $part;
    }

    public function testProductAliasRefKeepsItsNameOnTheLabelWhileSpellingVariantsDoNot(): void
    {
        $task = $this->task([]);

        $task->mergeParts([['ref' => 'LOCTITE-243'], ['ref' => 'dot4', 'label' => 'Liquide de frein']]);

        $parts = array_map(self::withoutId(...), $task->getParts());
        self::assertSame(['ref' => 'frein-filet moyen', 'label' => 'LOCTITE-243'], $parts[0]);
        self::assertSame(['ref' => 'DOT 4', 'label' => 'Liquide de frein'], $parts[1]);
    }

    public function testReplanWithProductAliasMatchesExistingLineAndAppendsNameToItsLabel(): void
    {
        $task = $this->task([['ref' => 'frein-filet moyen', 'label' => 'Frein filet']]);

        $task->mergeParts([['ref' => 'Loctite 243', 'qty' => 1]]);

        $parts = $task->getParts();
        self::assertCount(1, $parts);
        self::assertSame('Frein filet (Loctite 243)', $parts[0]['label']);
        self::assertSame(1, $parts[0]['qty']);
    }

    /**
     * @param list<array{ref: string, label?: string, brand?: string, qty?: int, priceCents?: int, price?: float, supplier?: string, id?: string}> $parts
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
