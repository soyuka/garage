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
            (string) file_get_contents(__DIR__.'/../../Fixtures/catalog-rows/getArticles_19107_PC_100259.json'),
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

    // ── brand filter ────────────────────────────────────────────────────────────────────────────
    // Without it, "what about JAPANPARTS?" is unanswerable: the ranked slice keeps 6 of 126 brands, so a
    // brand the operator names is usually absent from the payload and the model answers from its weights.

    public function testABrandFilterKeepsOnlyTheAskedBrands(): void
    {
        $group = ArticleGroup::of(100259, $this->oilFilterArticles(), brands: ['JAPANPARTS', 'ASHIKA']);

        // Kept in RANK order, not the order asked and not alphabetical: both are tier 2, and JAPANPARTS
        // sits ahead of ASHIKA in the curated list — so the payload itself carries the recommendation.
        $this->assertSame(['JAPANPARTS', 'ASHIKA'], array_column($group['brands'], 'brand'));
        $this->assertSame([2, 2], array_column($group['brands'], 'tier'));
    }

    public function testABrandFilterIgnoresTheSixBrandCap(): void
    {
        $asked = ['MANN-FILTER', 'PURFLUX', 'BLUE PRINT', 'NPS', 'JAPANPARTS', 'ASHIKA', 'MAHLE', 'BOSCH'];
        $group = ArticleGroup::of(100259, $this->oilFilterArticles(), brands: $asked);

        $this->assertGreaterThan(ArticleGroup::MAX_BRANDS, \count($group['brands']));
        $this->assertFalse($group['truncated'], 'a filtered answer is complete for what was asked');
    }

    public function testABrandFilterMatchesRegardlessOfCaseAndPunctuation(): void
    {
        $group = ArticleGroup::of(100259, $this->oilFilterArticles(), brands: ['mann filter']);

        $this->assertSame(['MANN-FILTER'], array_column($group['brands'], 'brand'));
    }

    public function testAnAskedBrandThatDoesNotFitIsNamedAsMissing(): void
    {
        // The honesty seam: "we do not carry it for this part" and "you asked wrong" must not look alike,
        // and silence here is what let the agent invent a comparison for brands it never saw.
        $group = ArticleGroup::of(100259, $this->oilFilterArticles(), brands: ['JAPANPARTS', 'BRAND-THAT-DOES-NOT-FIT']);

        $this->assertSame(['BRAND-THAT-DOES-NOT-FIT'], $group['missing']);
        $this->assertSame(['JAPANPARTS'], array_column($group['brands'], 'brand'));
    }

    public function testTotalsStillDescribeTheWholeCategoryUnderAFilter(): void
    {
        $group = ArticleGroup::of(100259, $this->oilFilterArticles(), brands: ['JAPANPARTS']);

        $this->assertSame(220, $group['totalArticles'], 'the category is still 220 articles deep');
        $this->assertSame(126, $group['totalBrands']);
    }

    public function testNoFilterBehavesExactlyAsBefore(): void
    {
        $this->assertSame(
            ArticleGroup::of(100259, $this->oilFilterArticles()),
            ArticleGroup::of(100259, $this->oilFilterArticles(), brands: []),
        );
    }
}
