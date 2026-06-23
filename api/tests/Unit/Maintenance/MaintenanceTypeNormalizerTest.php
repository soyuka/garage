<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Maintenance\MaintenanceTypeNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaintenanceTypeNormalizerTest extends TestCase
{
    private MaintenanceTypeNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new MaintenanceTypeNormalizer();
    }

    #[DataProvider('knownAliases')]
    public function testMapsKnownAliasToCanonicalType(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->canonical($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function knownAliases(): iterable
    {
        // The bug that started this: the French label leaked in as the type.
        yield 'French label "Huile"' => ['Huile', 'oil_change'];
        yield 'lowercase vidange' => ['vidange', 'oil_change'];
        yield 'huile moteur' => ['huile moteur', 'oil_change'];
        yield 'English "oil change"' => ['oil change', 'oil_change'];
        yield 'filtre à huile' => ['Filtre à huile', 'oil_filter'];
        yield 'filtre à air' => ['Filtre à air', 'air_filter'];
        yield 'air filter EN' => ['air filter', 'air_filter'];
        yield 'filtre à carburant' => ['Filtre à carburant', 'fuel_filter'];
        yield 'bougie singular' => ['Bougie', 'spark_plug'];
        yield 'bougies plural' => ['Bougies', 'spark_plug'];
        yield "bougie d'allumage" => ["bougie d'allumage", 'spark_plug'];
        yield 'pneu' => ['Pneu', 'tires'];
        yield 'pneus' => ['Pneus', 'tires'];
        yield 'tyre EN' => ['tyre', 'tires'];
        yield 'batterie' => ['Batterie', 'battery'];
        yield 'freins' => ['Freins', 'brakes'];
        yield 'liquide de refroidissement' => ['liquide de refroidissement', 'coolant'];
        yield 'contrôle technique' => ['Contrôle technique', 'inspection'];
    }

    public function testAlreadyCanonicalPassesThrough(): void
    {
        self::assertSame('oil_change', $this->normalizer->canonical('oil_change'));
        self::assertSame('spark_plug', $this->normalizer->canonical('spark_plug'));
    }

    public function testUnknownLongTailIsKeptVerbatim(): void
    {
        // The whole point: services we never planned must still log, untouched, so they are not lost.
        self::assertSame('fuel_line', $this->normalizer->canonical('fuel_line'));
        self::assertSame('Housse de siège', $this->normalizer->canonical('Housse de siège'));
    }

    public function testTrimsSurroundingWhitespaceBeforeMatching(): void
    {
        self::assertSame('oil_change', $this->normalizer->canonical('  Huile  '));
        // An unknown value keeps its own content but loses only the surrounding whitespace.
        self::assertSame('Durite spéciale', $this->normalizer->canonical('  Durite spéciale  '));
    }

    public function testNormalizesEveryItemTypeInPlace(): void
    {
        $items = [
            ['type' => 'Huile', 'parts' => [['ref' => 'X']]],
            ['type' => 'Bougies', 'parts' => []],
            ['type' => 'fuel_line', 'parts' => []],
        ];

        $normalized = $this->normalizer->normalizeItems($items);

        self::assertSame('oil_change', $normalized[0]['type']);
        self::assertSame('spark_plug', $normalized[1]['type']);
        self::assertSame('fuel_line', $normalized[2]['type']);
        // Parts and other keys ride along untouched.
        self::assertSame([['ref' => 'X']], $normalized[0]['parts']);
    }
}
