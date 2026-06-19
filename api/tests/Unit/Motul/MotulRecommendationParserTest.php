<?php

declare(strict_types=1);

namespace App\Tests\Unit\Motul;

use App\Motul\MotulRecommendationParser;
use App\Motul\NextData;
use PHPUnit\Framework\TestCase;

final class MotulRecommendationParserTest extends TestCase
{
    private static function recommendations(): array
    {
        $html = file_get_contents(__DIR__.'/../../Fixtures/motul/recommendations_9950.html');

        return (new MotulRecommendationParser())->fromNextData(NextData::extract($html));
    }

    public function testExtractsEngineViscosityFromDehydratedPool(): void
    {
        $engine = array_values(array_filter(self::recommendations(), static fn ($r) => $r->isEngine()));

        $this->assertNotEmpty($engine);
        $this->assertSame(['5W-30'], array_values(array_unique(array_map(static fn ($r) => $r->viscosity, $engine))));
    }

    public function testResolvesAllRecommendationFields(): void
    {
        $first = self::recommendations()[0];

        $this->assertSame('Engine', $first->application);
        $this->assertSame('5W-30', $first->viscosity);
        $this->assertNotSame('', $first->sku);
        $this->assertMatchesRegularExpression('/^\d+L$/', $first->quantity);
    }

    public function testCapturesTransmissionGrades(): void
    {
        $trans = array_filter(self::recommendations(), static fn ($r) => 0 === strcasecmp($r->application, 'Transmission'));
        $grades = array_unique(array_map(static fn ($r) => $r->viscosity, $trans));

        $this->assertContains('75W-90', $grades);
    }
}
