<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\ApiResource\Article;
use App\Mcp\ArticleGroup;
use PHPUnit\Framework\TestCase;

/**
 * Pinned against the REAL partsapi response for the Mazda6 2.0 (carId 19107) oil filter
 * category: 220 fitment-correct articles, 126 brands, alphabetical by brand. Proves the
 * ranking surfaces recognizable OE brands that the old blind alphabetical slice buried
 * (MANN-FILTER was at position 109, PURFLUX at 164).
 */
final class ArticleGroupTest extends TestCase
{
    /**
     * @return list<Article>
     */
    private function oilFilterArticles(): array
    {
        $rows = json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/partsapi/getArticles_19107_PC_100259.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        return array_map(Article::fromPartsApi(...), $rows);
    }

    public function testKeepsFullTotalsRegardlessOfTruncation(): void
    {
        $group = ArticleGroup::of(100259, $this->oilFilterArticles());

        $this->assertSame(100259, $group['categoryId']);
        $this->assertSame(220, $group['totalArticles']);
        $this->assertSame(126, $group['totalBrands']);
        $this->assertTrue($group['truncated']);
    }

    public function testSurfacesPremiumBrandsThatAlphabeticalSliceBuried(): void
    {
        $group = ArticleGroup::of(100259, $this->oilFilterArticles());
        $brands = array_column($group['brands'], 'brand');

        $this->assertContains('MANN-FILTER', $brands);
        $this->assertContains('PURFLUX', $brands);
        $this->assertSame('MANN-FILTER', $brands[0], 'the top OE brand must lead');
    }

    public function testRanksByTierThenCuratedPriority(): void
    {
        $group = ArticleGroup::of(100259, $this->oilFilterArticles());

        // every surfaced brand is tier 1 (the category has far more than MAX_BRANDS tier-1 brands)
        foreach ($group['brands'] as $b) {
            $this->assertSame(1, $b['tier']);
        }
        $this->assertCount(ArticleGroup::MAX_BRANDS, $group['brands']);
    }

    public function testKeepsEveryFittingRefPerSurfacedBrand(): void
    {
        $group = ArticleGroup::of(100259, $this->oilFilterArticles());
        $byBrand = [];
        foreach ($group['brands'] as $b) {
            $byBrand[$b['brand']] = array_column($b['articles'], 'number');
        }

        $this->assertSame(['W 712/73'], $byBrand['MANN-FILTER']);
        // multi-variant brand: both fitting refs are kept, never silently dropped
        $this->assertEqualsCanonicalizing(['LS1176', 'LS357'], $byBrand['PURFLUX']);
    }

    public function testFallsBackToTopBrandsWhenNoPremiumPresent(): void
    {
        $rows = [
            ['ART_ID' => 1, 'ART_ARTICLE_NR' => 'X1', 'ART_SUP_BRAND' => 'OSSCA', 'PRODUCT GROUP' => 'Oil Filter', 'PT_ID' => 1, 'SUP_ID' => 1],
            ['ART_ID' => 2, 'ART_ARTICLE_NR' => 'Y1', 'ART_SUP_BRAND' => 'WILMINK GROUP', 'PRODUCT GROUP' => 'Oil Filter', 'PT_ID' => 1, 'SUP_ID' => 2],
        ];
        $group = ArticleGroup::of(100259, array_map(Article::fromPartsApi(...), $rows));

        $this->assertSame(2, $group['totalBrands']);
        $this->assertFalse($group['truncated']);
        $this->assertCount(2, $group['brands']);
    }
}
