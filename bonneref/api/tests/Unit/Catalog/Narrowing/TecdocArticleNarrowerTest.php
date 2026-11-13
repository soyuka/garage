<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\CarType;
use App\Catalog\Narrowing\ArticleNarrower;
use App\Catalog\Narrowing\NarrowingResult;
use App\Catalog\TecdocCatalog;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Tests\Support\TecdocFixtureDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class TecdocArticleNarrowerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tecdoc-narrow-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        TecdocFixtureDatabase::create($this->dir.'/catalog.sqlite', withCategoryArticles: true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testTheMazdaOilFilterClassifiesMostOfTheCategoryFromLocalOeNumbers(): void
    {
        $result = (new ArticleNarrower(new TecdocCatalog($this->dir.'/catalog.sqlite')))
            ->narrow(19107, CarType::PassengerCar, 100259, 'MAZDA');

        $this->assertTrue($result->narrowed);
        $this->assertSame(116, $result->totalArticles);
        $this->assertSame(87, $result->totalBrands);

        // The rest are cartridge filters no screw-on OE number reaches, and articles without any OE number or criterion.
        $this->assertLessThanOrEqual(25, $result->unclassifiedCount);
        $this->assertCount(2, $result->clusters);
        $this->assertSame(116, array_sum(array_map(static fn ($c): int => $c->articleCount, $result->clusters)) + $result->unclassifiedCount);
    }

    public function testFrenchAndEnglishCallersGetTheSameClusters(): void
    {
        $inFrench = $this->narrowIn(Locale::Fr);
        $inEnglish = $this->narrowIn(Locale::En);

        $this->assertCount(2, $inFrench->clusters);
        $this->assertSame(self::membership($inFrench), self::membership($inEnglish));
        $this->assertSame($inFrench->unclassifiedCount, $inEnglish->unclassifiedCount);
        $this->assertSame(array_column($inFrench->differsOn, 'key'), array_column($inEnglish->differsOn, 'key'));
        $this->assertSame(
            array_map(static fn ($c): array => array_map(static fn (array $e): array => array_diff_key($e, ['label' => 1, 'value' => 1]), $c->fingerprint), $inFrench->clusters),
            array_map(static fn ($c): array => array_map(static fn (array $e): array => array_diff_key($e, ['label' => 1, 'value' => 1]), $c->fingerprint), $inEnglish->clusters),
        );
        $this->assertSame(array_map(static fn ($c): array => $c->oeNumbers, $inFrench->clusters), array_map(static fn ($c): array => $c->oeNumbers, $inEnglish->clusters));
    }

    public function testAnEnglishCallerReadsTheLabelsAndValuesInEnglish(): void
    {
        $inFrench = $this->narrowIn(Locale::Fr);
        $inEnglish = $this->narrowIn(Locale::En);

        $french = $inFrench->clusters[0]->fingerprint;
        $english = $inEnglish->clusters[0]->fingerprint;

        $this->assertSame('Hauteur [mm]', $french['hauteur']['label']);
        $this->assertSame('Height [mm]', $english['hauteur']['label']);
        $this->assertSame('Type de filtre', $french['type_de_filtre']['label']);
        $this->assertSame('Filter type', $english['type_de_filtre']['label']);
        $this->assertSame('Filtre vissé', $french['type_de_filtre']['value']);
        $this->assertSame('Screw-on Filter', $english['type_de_filtre']['value']);
        $this->assertSame('Oil Filter', $inEnglish->productGroup['name'] ?? null);
    }

    public function testTheCarmakerComesFromTheCatalogueWhenTheCallerGivesNone(): void
    {
        $catalog = new TecdocCatalog($this->dir.'/catalog.sqlite');

        $withoutMake = (new ArticleNarrower($catalog))->narrow(19107, CarType::PassengerCar, 100259);
        $withMake = (new ArticleNarrower($catalog))->narrow(19107, CarType::PassengerCar, 100259, 'MAZDA');
        $wrongMake = (new ArticleNarrower($catalog))->narrow(19107, CarType::PassengerCar, 100259, 'PEUGEOT');

        $this->assertCount(2, $withoutMake->clusters);
        $this->assertSame($withMake->toArray(), $withoutMake->toArray());
        $this->assertSame($withMake->toArray(), $wrongMake->toArray());
    }

    public function testEachEspaceFlywheelCarriesTheQualifiersOfItsOwnLinkToThisCar(): void
    {
        TecdocFixtureDatabase::create($this->dir.'/espace.sqlite', withEspaceFlywheels: true);

        $result = (new ArticleNarrower(new TecdocCatalog($this->dir.'/espace.sqlite')))
            ->narrow(18679, CarType::PassengerCar, 100057, 'RENAULT');

        $this->assertSame('vehicle', $result->toArray()['applicabilityScope']);
        $variants = [];
        $shared = [];
        foreach ($result->clusters as $cluster) {
            foreach ($cluster->variants as $variant) {
                $variants[$variant['artId']] = $variant['applicability'] ?? null;
                $shared[$variant['artId']] = $cluster->applicability;
            }
        }

        $this->assertSame([], $shared[95481], 'the SACHS date must not reach the LuK sharing its cluster');
        $this->assertSame([['name' => "Année jusqu'à", 'values' => ['200602']]], $shared[635154]);

        $this->assertSame([['conditions' => [], 'pairedWith' => ['623 3150 ##']]], $variants[95481]);
        $this->assertSame(
            [[
                'conditions' => [
                    ['name' => "Année jusqu'à", 'values' => ['200602']],
                    ['name' => "Type d'entraînement", 'values' => ['Boîte de vitesses manuelle à 6 vitesses']],
                ],
                'pairedWith' => ['3000 951 220'],
            ]],
            $variants[635192],
        );
        $this->assertSame([['conditions' => [['name' => 'Code moteur', 'values' => ['F4R 797/897']]], 'pairedWith' => ['962318']]], $variants[3097422]);
        $this->assertSame([['conditions' => [['name' => "Année jusqu'à", 'values' => ['200602']]], 'pairedWith' => []]], $variants[635154]);
    }

    private function narrowIn(Locale $locale): NarrowingResult
    {
        $context = new LocaleContext(new RequestStack());
        $context->set($locale);

        return (new ArticleNarrower(new TecdocCatalog($this->dir.'/catalog.sqlite', locale: $context)))->narrow(19107, CarType::PassengerCar, 100259, 'MAZDA');
    }

    /**
     * @return array<string, list<int>> cluster id => its articles, sorted
     */
    private static function membership(NarrowingResult $result): array
    {
        $members = [];
        foreach ($result->clusters as $cluster) {
            $ids = array_column($cluster->variants, 'artId');
            sort($ids);
            $members[$cluster->id] = $ids;
        }

        return $members;
    }
}
