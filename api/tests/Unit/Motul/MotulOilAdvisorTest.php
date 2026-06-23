<?php

declare(strict_types=1);

namespace App\Tests\Unit\Motul;

use App\Motul\MotulClient;
use App\Motul\MotulOilAdvisor;
use App\Motul\MotulRecommendationParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MotulOilAdvisorTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/motul/'.$name);
    }

    private static function advisor(?string $searchBody = null): MotulOilAdvisor
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, 'search-vehicle')) {
                return new MockResponse(self::fixture('search-vehicle_mazda6.json'));
            }

            return new MockResponse(self::fixture('recommendations_9950.html'));
        });

        return new MotulOilAdvisor(new MotulClient($http, new ArrayAdapter(), new MotulRecommendationParser()));
    }

    public function testYearAndEnginePinExactVariantAndReturnGrade(): void
    {
        $result = self::advisor()->advise('mazda 6', 2004, '1.8');

        $this->assertSame('matched', $result['status']);
        $this->assertSame('9950', $result['vehicle']['id']);
        $this->assertSame(['5W-30'], $result['engineOilGrades']);
        $this->assertStringContainsString('recommendations/9950', $result['sourceUrl']);
    }

    public function testMatchedResultCarriesIntervalCapacityAndAcea(): void
    {
        $result = self::advisor()->advise('mazda 6', 2004, '1.8');

        $this->assertSame('20000 km/ 12 months', $result['oilChangeInterval']);
        $this->assertContains('4.6 L', $result['engineOilCapacities']);
        $this->assertNotEmpty($result['aceaStandards']);
        // grade is condition-invariant here, so no per-condition breakdown
        $this->assertArrayNotHasKey('gradeByCondition', $result);
    }

    public function testMotulIdPinsVariantDirectlyWithoutSearch(): void
    {
        // No search-vehicle call: the variant is addressed straight by its Motul id, so an ambiguous
        // first turn can converge on the exact row the user picked.
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, 'search-vehicle')) {
                throw new \LogicException('adviseVariant must not search; it pins by id.');
            }

            return new MockResponse(self::fixture('recommendations_9950.html'));
        });
        $advisor = new MotulOilAdvisor(new MotulClient($http, new ArrayAdapter(), new MotulRecommendationParser()));

        $result = $advisor->adviseVariant('9950');

        $this->assertSame('matched', $result['status']);
        $this->assertSame('9950', $result['vehicle']['id']);
        $this->assertSame(['5W-30'], $result['engineOilGrades']);
        $this->assertStringContainsString('recommendations/9950', $result['sourceUrl']);
    }

    public function testAmbiguousWhenSeveralVariantsMatch(): void
    {
        $result = self::advisor()->advise('mazda 6', 2004);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertGreaterThan(1, \count($result['candidates']));
        $this->assertArrayNotHasKey('engineOilGrades', $result);
    }

    public function testNotFoundWhenSearchReturnsNoVehicles(): void
    {
        $http = new MockHttpClient(new MockResponse('{"vehicles":[]}'));
        $advisor = new MotulOilAdvisor(new MotulClient($http, new ArrayAdapter(), new MotulRecommendationParser()));

        $result = $advisor->advise('nonexistent machine', 2004);

        $this->assertSame('not_found', $result['status']);
        $this->assertSame([], $result['candidates']);
    }

    /**
     * Motul's fuzzy search-vehicle returns "Maeda" mini-cranes for a "Mazda 6" query (real incident:
     * 26 cranes + an RX-8, no Mazda 6). The crane year range is an absurd 1950-2027, so year-narrowing
     * cannot drop them — only a brand/model relevance filter can.
     */
    private static function noisyAdvisor(): MotulOilAdvisor
    {
        $vehicles = json_encode(['vehicles' => [
            ['id' => '111', 'brand' => 'Maeda', 'model' => 'Mini-grues sur chenilles', 'type' => 'MC-1033CE-1', 'startYear' => 1950, 'endYear' => 2027],
            ['id' => '222', 'brand' => 'Maeda', 'model' => 'Mini-grues sur chenilles', 'type' => 'MC-285C-2', 'startYear' => 1950, 'endYear' => 2027],
            ['id' => '333', 'brand' => 'Maeda', 'model' => 'Mini-grues sur chenilles', 'type' => 'MC-305C', 'startYear' => 1950, 'endYear' => 2027],
            ['id' => '10914', 'brand' => 'Mazda (EU)', 'model' => 'RX-8', 'type' => 'RX-8 HP', 'startYear' => 2003, 'endYear' => 2011],
            ['id' => '9950', 'brand' => 'Mazda (EU)', 'model' => '6', 'type' => '6 1.8', 'startYear' => 2002, 'endYear' => 2008],
        ]]);
        $http = new MockHttpClient(static fn (string $m, string $url): MockResponse => str_contains($url, 'search-vehicle')
            ? new MockResponse($vehicles)
            : new MockResponse(self::fixture('recommendations_9950.html')));

        return new MotulOilAdvisor(new MotulClient($http, new ArrayAdapter(), new MotulRecommendationParser()));
    }

    public function testDropsBrandAndModelMismatchedSearchNoise(): void
    {
        $result = self::noisyAdvisor()->advise('mazda 6', 2007);

        // Maeda cranes (brand) and the RX-8 (model) are dropped, leaving the one Mazda 6 variant.
        $this->assertSame('matched', $result['status']);
        $this->assertSame('9950', $result['vehicle']['id']);
    }

    public function testMatchesWhenSearchModelTokenRepeatsTheMake(): void
    {
        // The @id path builds search = make+" "+model = "MAZDA MAZDA6", while Motul's model is bare "6".
        // The relevance filter must still keep the Mazda 6 (not drop it for the "mazda6" vs "6" mismatch).
        $result = self::noisyAdvisor()->advise('MAZDA MAZDA6', 2007);

        $this->assertSame('matched', $result['status']);
        $this->assertSame('9950', $result['vehicle']['id']);
    }

    public function testNotFoundCandidatesNeverLeakBrandMismatchedNoise(): void
    {
        // A query with no real match must come back not_found with NO candidates — never a dump of the
        // brand-mismatched junk Motul's search returned.
        $result = self::noisyAdvisor()->advise('peugeot 208', 2007);

        $this->assertSame('not_found', $result['status']);
        $this->assertSame([], $result['candidates']);
    }
}
