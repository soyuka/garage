<?php

declare(strict_types=1);

namespace App\Tests\Unit\Motul;

use App\Motul\MotulCategory;
use App\Motul\MotulRecommendationParser;
use App\Motul\NextData;
use PHPUnit\Framework\TestCase;

final class MotulRecommendationParserTest extends TestCase
{
    /**
     * @return list<MotulCategory>
     */
    private static function categories(): array
    {
        $html = file_get_contents(__DIR__.'/../../Fixtures/motul/recommendations_9950.html');

        return (new MotulRecommendationParser())->fromNextData(NextData::extract($html));
    }

    private static function category(string $name): MotulCategory
    {
        foreach (self::categories() as $c) {
            if ($c->name === $name) {
                return $c;
            }
        }

        self::fail("no category $name");
    }

    public function testEngineCategoryCarriesGradeCapacityAndInterval(): void
    {
        $engine = self::category('Engine');

        $this->assertSame(['5W-30'], $engine->viscosities());
        // capacity labels off the page (with-filter / drain variants)
        $this->assertNotEmpty($engine->capacities);
        $this->assertSame('20000 km/ 12 months', $engine->intervalForUsage('Normal'));
    }

    public function testEngineGradeIsConditionInvariantHere(): void
    {
        // every usage×mileage cell agrees on the grade for this vehicle
        $this->assertFalse(self::category('Engine')->gradeVariesByCondition());
    }

    public function testCapturesAceaQualityStandards(): void
    {
        $acea = self::category('Engine')->aceaStandards;
        $joined = implode(' ', $acea);

        $this->assertStringContainsString('ACEA', $joined);
    }

    public function testTransmissionGradesParsed(): void
    {
        $this->assertContains('75W-90', self::category('Transaxle, manual')->viscosities());
    }
}
