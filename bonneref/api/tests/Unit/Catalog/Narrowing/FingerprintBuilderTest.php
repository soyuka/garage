<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;
use App\Catalog\Narrowing\FingerprintBuilder;
use App\Tests\Support\TecdocCriteria;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FingerprintBuilderTest extends TestCase
{
    public function testKeepsPhysicalCriteriaOfTheMannFilter(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria(self::fixture('getArticleCriteria_9674372.json'));

        $this->assertEqualsCanonicalizing(
            [
                'diametre_exterieur',
                'hauteur',
                'diametre_exterieur_de_la_bague_d_etancheite',
                'diametre_interieur_de_la_bague_d_etancheite',
                'thread',
                'pression_d_ouverture_clapet_de_derivation',
                'type_de_filtre',
            ],
            array_keys($fingerprint->entries),
        );
        $this->assertSame(93.0, $fingerprint->entries['hauteur']->number);
        $this->assertSame('mm', $fingerprint->entries['hauteur']->unit);
        $this->assertSame('Hauteur [mm]', $fingerprint->entries['hauteur']->label);
        $this->assertSame('3/4-16', $fingerprint->entries['thread']->value);
        $this->assertSame(1.0, $fingerprint->entries['pression_d_ouverture_clapet_de_derivation']->number);
    }

    public function testTheTecdoc2020SealDiametersKeyOnTheirCriterionIdNotTheirRenamedLabel(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([
            TecdocCriteria::of('Hauteur [mm]', '93'),
            TecdocCriteria::of('Diamètre extérieur [mm]', '76'),
            TecdocCriteria::of('Diamètre extérieur 2 [mm]', '71'),
            TecdocCriteria::of('Diamètre intérieur 2 [mm]', '62'),
            TecdocCriteria::of('Filetage', '3/4-16 UNF'),
            TecdocCriteria::of('Diamètre 3 [mm]', '50'),
        ]);

        $this->assertEqualsCanonicalizing(
            ['hauteur', 'diametre_exterieur', 'diametre_exterieur_de_la_bague_d_etancheite', 'diametre_interieur_de_la_bague_d_etancheite', 'thread', 'diametre_3'],
            array_keys($fingerprint->entries),
        );
        $this->assertFalse($fingerprint->entries['diametre_exterieur_de_la_bague_d_etancheite']->info);
        $this->assertSame('Diamètre extérieur 2 [mm]', $fingerprint->entries['diametre_exterieur_de_la_bague_d_etancheite']->label);
        $this->assertTrue($fingerprint->entries['diametre_3']->info);
    }

    public function testACriterionWithoutAnIdIsNeverClassified(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([new ArticleCriterion('Hauteur [mm]', '93')]);

        $this->assertTrue($fingerprint->isEmpty());
    }

    public function testAnEnglishCriterionClassifiesLikeItsFrenchTwinAndOnlyItsLabelIsEnglish(): void
    {
        $french = FingerprintBuilder::fromCriteria([
            TecdocCriteria::of('Hauteur [mm]', '93'),
            TecdocCriteria::of('Diamètre extérieur 2 [mm]', '71'),
            TecdocCriteria::of('Diamètre 3 [mm]', '50'),
            TecdocCriteria::of('Filetage', '3/4-16 UNF'),
            new ArticleCriterion('Type de filtre', 'Filtre vissé', 826, null, 'Type de filtre', '1'),
            TecdocCriteria::of('Code moteur', 'F4R'),
        ]);
        $english = FingerprintBuilder::fromCriteria([
            new ArticleCriterion('Height [mm]', '93', 209, 'mm', 'Hauteur [mm]', '93'),
            new ArticleCriterion('Outer diameter 2 [mm]', '71', 979, 'mm', 'Diamètre extérieur 2 [mm]', '71'),
            new ArticleCriterion('Diameter 3 [mm]', '50', 343, 'mm', 'Diamètre 3 [mm]', '50'),
            new ArticleCriterion('Thread Size', '3/4-16 UNF', 289, null, 'Filetage', '3/4-16 UNF'),
            new ArticleCriterion('Filter type', 'Screw-on Filter', 826, null, 'Type de filtre', '1'),
            new ArticleCriterion('Engine Code', 'F4R', 33, null, 'Code moteur', 'F4R'),
        ]);

        $this->assertSame(array_keys($french->entries), array_keys($english->entries));
        $this->assertSame(['Height [mm]', 'Outer diameter 2 [mm]', 'Diameter 3 [mm]', 'Thread Size', 'Filter type'], array_values(array_map(static fn ($e): string => $e->label, $english->entries)));
        $this->assertSame('Screw-on Filter', $english->entries['type_de_filtre']->value);
        $this->assertTrue($french->agreesWith($english));
    }

    public function testADiscreteKeyIsComparedOnItsStoredCodeNotItsTranslatedText(): void
    {
        $left = FingerprintBuilder::fromCriteria([new ArticleCriterion('Fitting Position', 'Front', 100, null, "Côté d'assemblage", 'A')]);
        $right = FingerprintBuilder::fromCriteria([new ArticleCriterion('Fitting Position', 'Front', 100, null, "Côté d'assemblage", 'B')]);

        $this->assertFalse($left->agreesWith($right), 'two keys translated to the same English text are still two keys');
    }

    public function testTheTecdoc2020ChassisAndSerialSpellingsNeverSplit(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([
            TecdocCriteria::of('Nº de châssis à partir de', '176451; 130831'),
            TecdocCriteria::of('Nº de série', 'GG; GY'),
            TecdocCriteria::of('Année à partir de', '200406; 200907'),
        ]);

        $this->assertTrue($fingerprint->isEmpty());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function threadSpellings(): iterable
    {
        yield 'plain' => ['3/4-16', '3/4-16'];
        yield 'unf and class' => ['3/4-16unf-2b', '3/4-16'];
        yield 'missing dash' => ['3/416unf-2b', '3/4-16'];
        yield 'leading standard' => ['unf3/4-16', '3/4-16'];
        yield 'quoted' => ['3/4"-16', '3/4-16'];
        yield 'spaced' => ['3/4" 16 UNF-2B', '3/4-16'];
        yield 'standard first' => ['UNF 3/4" -16', '3/4-16'];
        yield 'sixteenth' => ['7/16-20 UNF', '7/16-20'];
        yield 'metric' => ['M 20 x 1,5', 'M20x1.5'];
        yield 'metric tight' => ['M22X1.5', 'M22x1.5'];
        yield 'unknown kept lowercased' => ['  Rp 1/8 ', 'rp 1/8'];
    }

    #[DataProvider('threadSpellings')]
    public function testThreadSpellingsCanonicalise(string $raw, string $expected): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([TecdocCriteria::of('Filetage', $raw)]);

        $this->assertSame($expected, $fingerprint->entries['thread']->value);
    }

    public function testBareDiameterIsTheOuterDiameterAndSealSpellingsCollapse(): void
    {
        $mahle = FingerprintBuilder::fromCriteria([
            TecdocCriteria::of('Diamètre [mm]', '76,2'),
            TecdocCriteria::of('Diamètre de la bague détanchéité [mm]', '62,0'),
        ]);
        $mann = FingerprintBuilder::fromCriteria([
            TecdocCriteria::of('Diamètre intérieur 2 [mm]', '62'),
        ]);

        $this->assertSame(76.2, $mahle->entries['diametre_exterieur']->number);
        $this->assertSame(['diametre_exterieur', 'diametre_interieur_de_la_bague_d_etancheite'], array_keys($mahle->entries));
        $this->assertSame(['diametre_interieur_de_la_bague_d_etancheite'], array_keys($mann->entries));
        $this->assertFalse($mahle->entries['diametre_interieur_de_la_bague_d_etancheite']->info);
        $this->assertSame('mm', $mann->entries['diametre_interieur_de_la_bague_d_etancheite']->unit);
    }

    public function testNumberedDiametersAndMeasuredSupplierFiguresAreInfoOnly(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([
            TecdocCriteria::of('Diamètre 3 [mm]', '62'),
            TecdocCriteria::of('Diamètre intérieur [mm]', '62,5'),
            TecdocCriteria::of("Hauteur de l'emballage [cm]", '10,00'),
            TecdocCriteria::of('Poids net [g]', '294'),
            TecdocCriteria::of('Couple de serrage [Nm]', '20'),
            TecdocCriteria::of("Pression d'ouverture clapet de dérivation [bar]", '2,4'),
            TecdocCriteria::of('Hauteur [mm]', '85'),
        ]);

        foreach ($fingerprint->entries as $key => $entry) {
            $this->assertSame('hauteur' !== $key, $entry->info, $key);
        }
        $this->assertCount(7, $fingerprint->entries);
    }

    public function testThreadSpellingsCollapseToTheSameValue(): void
    {
        $mann = FingerprintBuilder::fromCriteria(self::fixture('getArticleCriteria_9674372.json'));
        $purflux = FingerprintBuilder::fromCriteria(self::fixture('getArticleCriteria_27611375.json'));

        $this->assertSame('3/4-16', $purflux->entries['thread']->value);
        $this->assertSame($mann->entries['thread']->value, $purflux->entries['thread']->value);
        $this->assertSame('Taraudage/Filetage 1', $purflux->entries['thread']->label);
    }

    public function testApplicationLevelCriteriaAreNeverPhysical(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([
            TecdocCriteria::of('Année à partir de', '200304'),
            TecdocCriteria::of("Année jusqu'à", '200502'),
            TecdocCriteria::of('Code moteur', 'L501'),
            TecdocCriteria::of('Nº de série', 'GG'),
            TecdocCriteria::of('Nº de châssis à partir de', '130831'),
            TecdocCriteria::of('numéro TECDOC du moteur', '18205'),
            TecdocCriteria::of("Référence de l'outil spécialisé recommandé", 'LS 7'),
            TecdocCriteria::of('Hauteur [mm]', '85'),
        ]);

        $this->assertSame(['hauteur'], array_keys($fingerprint->entries));
    }

    public function testMultiValuedCriteriaAreApplicationLevelEvenWithAUnit(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([
            TecdocCriteria::of('Hauteur [mm]', '85; 93'),
            TecdocCriteria::of('Diamètre extérieur [mm]', '76'),
        ]);

        $this->assertSame(['diametre_exterieur'], array_keys($fingerprint->entries));
    }

    public function testCommaDecimalsAndQuotedThreadsAreNormalised(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([
            TecdocCriteria::of('Hauteur [mm]', '93,5'),
            TecdocCriteria::of('Filetage', '3/4"-16 UNF'),
        ]);

        $this->assertSame(93.5, $fingerprint->entries['hauteur']->number);
        $this->assertSame('3/4-16', $fingerprint->entries['thread']->value);
    }

    public function testUnknownUnitlessCriteriaAreIgnored(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([TecdocCriteria::of('Couleur', 'noir')]);

        $this->assertTrue($fingerprint->isEmpty());
    }

    /**
     * @return list<ArticleCriterion>
     */
    private static function fixture(string $file): array
    {
        $rows = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/catalog-rows/'.$file), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($rows);

        return TecdocCriteria::fromPartsapiRows($rows);
    }
}
