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
}
