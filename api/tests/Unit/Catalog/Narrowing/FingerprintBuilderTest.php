<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;
use App\Catalog\Narrowing\FingerprintBuilder;
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
            new ArticleCriterion('Hauteur [mm]', '93', 209),
            new ArticleCriterion('Diamètre extérieur [mm]', '76', 497),
            new ArticleCriterion('Diamètre extérieur 2 [mm]', '71', 979),
            new ArticleCriterion('Diamètre intérieur 2 [mm]', '62', 996),
            new ArticleCriterion('Filetage', '3/4-16 UNF', 289),
            new ArticleCriterion('Diamètre extérieur 3 [mm]', '50', 3000),
        ]);

        $this->assertEqualsCanonicalizing(
            ['hauteur', 'diametre_exterieur', 'diametre_exterieur_de_la_bague_d_etancheite', 'diametre_interieur_de_la_bague_d_etancheite', 'thread', 'diametre_exterieur_3'],
            array_keys($fingerprint->entries),
        );
        $this->assertFalse($fingerprint->entries['diametre_exterieur_de_la_bague_d_etancheite']->info);
        $this->assertSame('Diamètre extérieur 2 [mm]', $fingerprint->entries['diametre_exterieur_de_la_bague_d_etancheite']->label);
        $this->assertTrue($fingerprint->entries['diametre_exterieur_3']->info);
    }

    public function testWithoutACriterionIdTheNameStillDecides(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([new ArticleCriterion('Diamètre extérieur 2 [mm]', '71')]);

        $this->assertTrue($fingerprint->entries['diametre_exterieur_2']->info);
    }

    public function testTheTecdoc2020ChassisAndSerialSpellingsNeverSplit(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([
            new ArticleCriterion('Nº de châssis à partir de', '176451; 130831'),
            new ArticleCriterion('Nº de série', 'GG; GY'),
            new ArticleCriterion('Année à partir de', '200406; 200907'),
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
        $fingerprint = FingerprintBuilder::fromCriteria([new ArticleCriterion('Filetage', $raw)]);

        $this->assertSame($expected, $fingerprint->entries['thread']->value);
    }

    public function testBareDiameterIsTheOuterDiameterAndSealSpellingsCollapse(): void
    {
        $mahle = FingerprintBuilder::fromCriteria([
            new ArticleCriterion('Diamètre [mm]', '76,2'),
            new ArticleCriterion('Diamètre de la bague détanchéité [mm]', '62,0'),
        ]);
        $mann = FingerprintBuilder::fromCriteria([
            new ArticleCriterion("Diamètre intérieur de la bague d'étanchéité [", '62'),
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
            new ArticleCriterion('Diamètre 3 [mm]', '62'),
            new ArticleCriterion('Diamètre intérieur 2 [mm]', '62'),
            new ArticleCriterion('Diamètre intérieur [mm]', '62,5'),
            new ArticleCriterion("Hauteur de l'emballage [cm]", '10,00'),
            new ArticleCriterion('Poids net [g]', '294'),
            new ArticleCriterion('Couple de serrage [Nm]', '20'),
            new ArticleCriterion("Pression d'ouverture clapet de dérivation [ba", '2,4'),
            new ArticleCriterion('Hauteur [mm]', '85'),
        ]);

        foreach ($fingerprint->entries as $key => $entry) {
            $this->assertSame('hauteur' !== $key, $entry->info, $key);
        }
        $this->assertCount(8, $fingerprint->entries);
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
            new ArticleCriterion('Année à partir de', '200304'),
            new ArticleCriterion("Année jusqu'à", '200502'),
            new ArticleCriterion('Code moteur', 'L501'),
            new ArticleCriterion('Nº de série', 'GG'),
            new ArticleCriterion('Numéro de châssis (VIN) à partir de', '130831'),
            new ArticleCriterion('numéro TECDOC du moteur', '18205'),
            new ArticleCriterion('pour le modèle de la série de modèles', '3'),
            new ArticleCriterion('Type de véhicule', '3'),
            new ArticleCriterion('Règlement européen sur la sécurité des produi', 'x'),
            new ArticleCriterion("Référence de l'outil spécialisé recommandé", 'LS 7'),
            new ArticleCriterion('Hauteur [mm]', '85'),
        ]);

        $this->assertSame(['hauteur'], array_keys($fingerprint->entries));
    }

    public function testMultiValuedCriteriaAreApplicationLevelEvenWithAUnit(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([
            new ArticleCriterion('Hauteur [mm]', '85; 93'),
            new ArticleCriterion('Diamètre extérieur [mm]', '76'),
        ]);

        $this->assertSame(['diametre_exterieur'], array_keys($fingerprint->entries));
    }

    public function testCommaDecimalsAndQuotedThreadsAreNormalised(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([
            new ArticleCriterion('Hauteur [mm]', '93,5'),
            new ArticleCriterion('Filetage', '3/4"-16 UNF'),
        ]);

        $this->assertSame(93.5, $fingerprint->entries['hauteur']->number);
        $this->assertSame('3/4-16', $fingerprint->entries['thread']->value);
    }

    public function testUnknownUnitlessCriteriaAreIgnored(): void
    {
        $fingerprint = FingerprintBuilder::fromCriteria([new ArticleCriterion('Couleur', 'noir')]);

        $this->assertTrue($fingerprint->isEmpty());
    }

    /**
     * @return list<ArticleCriterion>
     */
    private static function fixture(string $file): array
    {
        $rows = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/partsapi/'.$file), true, flags: \JSON_THROW_ON_ERROR);

        return array_map(ArticleCriterion::fromPartsApi(...), $rows);
    }
}
