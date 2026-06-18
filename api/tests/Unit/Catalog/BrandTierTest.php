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
}
