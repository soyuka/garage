<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\BrandTier;
use PHPUnit\Framework\TestCase;

final class BrandTierTest extends TestCase
{
    public function testClassifyReturnsTierForKnownBrands(): void
    {
        $this->assertSame(1, BrandTier::classify('MANN-FILTER'));
        $this->assertSame(1, BrandTier::classify('BOSCH'));
        $this->assertSame(2, BrandTier::classify('FEBI BILSTEIN'));
        $this->assertSame(3, BrandTier::classify('OSSCA'));
        $this->assertSame(3, BrandTier::classify('1A FIRST AUTOMOTIVE'));
    }

    public function testClassifyNormalizesPunctuationCaseAndSpacing(): void
    {
        $this->assertSame(1, BrandTier::classify('Mann Filter'));
        $this->assertSame(1, BrandTier::classify('mann-filter'));
        $this->assertSame(1, BrandTier::classify(' MANN-FILTER '));
        $this->assertSame(2, BrandTier::classify('febi bilstein'));
    }

    public function testRankOrdersPremiumBeforeAftermarketBeforeRest(): void
    {
        $this->assertLessThan(BrandTier::rank('MAHLE'), BrandTier::rank('MANN-FILTER'));
        $this->assertLessThan(BrandTier::rank('FEBI BILSTEIN'), BrandTier::rank('MAHLE'));
        $this->assertLessThan(BrandTier::rank('OSSCA'), BrandTier::rank('FEBI BILSTEIN'));
    }

    // ── rankAll: the curated ranking, made answerable ────────────────────────────────────────────
    // "japanparts ou ashika, tu conseilles quoi ?" is a question this list already answers, and the
    // agent used to answer it from its weights instead — inventing a justification we hold no data for.

    public function testRanksAskedBrandsBestFirst(): void
    {
        $ranked = BrandTier::rankAll(['ASHIKA', 'JAPANPARTS']);

        $this->assertSame(['JAPANPARTS', 'ASHIKA'], array_column($ranked, 'brand'));
    }

    public function testCarriesTheTierAndWhetherWeActuallyRankIt(): void
    {
        $ranked = BrandTier::rankAll(['MANN-FILTER', 'JAPANPARTS', 'NO-NAME PARTS CO']);

        $this->assertSame(
            [['MANN-FILTER', 1, true], ['JAPANPARTS', 2, true], ['NO-NAME PARTS CO', 3, false]],
            array_map(static fn (array $r): array => [$r['brand'], $r['tier'], $r['known']], $ranked),
        );
    }

    public function testAnUnlistedBrandIsUNKNOWNNotBad(): void
    {
        // tier 3 means "not on our curated list", which is not a quality verdict. Saying otherwise would
        // be inventing a claim, which is the exact failure this tool exists to stop.
        $ranked = BrandTier::rankAll(['SOME BRAND']);

        $this->assertFalse($ranked[0]['known']);
        $this->assertSame(3, $ranked[0]['tier']);
    }

    public function testMatchesRegardlessOfCaseAndPunctuation(): void
    {
        $this->assertSame(1, BrandTier::rankAll(['mann filter'])[0]['tier']);
    }

    public function testEchoesTheBrandAsTheOperatorSpelledIt(): void
    {
        $this->assertSame('mann filter', BrandTier::rankAll(['mann filter'])[0]['brand']);
    }

    public function testNoBrandsIsAnEmptyRanking(): void
    {
        $this->assertSame([], BrandTier::rankAll([]));
    }
}
