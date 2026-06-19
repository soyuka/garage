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
}
