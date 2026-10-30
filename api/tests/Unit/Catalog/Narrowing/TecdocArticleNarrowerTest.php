<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\Narrowing\ArticleNarrower;
use App\Catalog\TecdocCatalog;
use App\PartsApi\CarType;
use App\Tests\Support\TecdocFixtureDatabase;
use PHPUnit\Framework\TestCase;

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
        $this->assertSame(0, $result->upstreamCalls);

        // The rest are cartridge filters no screw-on OE number reaches, and articles without any OE number or criterion.
        $this->assertLessThanOrEqual(25, $result->unclassifiedCount);
        $this->assertCount(2, $result->clusters);
        $this->assertSame(116, array_sum(array_map(static fn ($c): int => $c->articleCount, $result->clusters)) + $result->unclassifiedCount);
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
}
