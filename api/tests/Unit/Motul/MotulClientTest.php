<?php

declare(strict_types=1);

namespace App\Tests\Unit\Motul;

use App\Motul\MotulClient;
use App\Motul\MotulRecommendationParser;
use App\Tests\Support\InMemoryMotulResponseStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MotulClientTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/motul/'.$name);
    }

    public function testSearchVehicleParsesCandidates(): void
    {
        $client = new MotulClient(
            new MockHttpClient(new MockResponse(self::fixture('search-vehicle_mazda6.json'))),
            new InMemoryMotulResponseStore(),
            new MotulRecommendationParser(),
        );

        $vehicles = $client->searchVehicle('mazda 6');

        $this->assertNotEmpty($vehicles);
        $byId = [];
        foreach ($vehicles as $v) {
            $byId[$v->id] = $v;
        }
        $this->assertArrayHasKey('9950', $byId);
        $this->assertSame('6', $byId['9950']->model);
        $this->assertTrue($byId['9950']->coversYear(2004));
        $this->assertFalse($byId['9950']->coversYear(2010));
    }

    public function testRecommendationsParsesEngineCategory(): void
    {
        $client = new MotulClient(
            new MockHttpClient(new MockResponse(self::fixture('recommendations_9950.html'))),
            new InMemoryMotulResponseStore(),
            new MotulRecommendationParser(),
        );

        $engine = array_values(array_filter($client->recommendations('9950'), static fn ($c) => $c->isEngine()))[0];

        $this->assertSame(['5W-30'], $engine->viscosities());
        $this->assertSame('20000 km/ 12 months', $engine->intervalForUsage('Normal'));
    }

    public function testSuccessfulResponseIsCachedNotRefetched(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::fixture('search-vehicle_mazda6.json'));
        });
        $client = new MotulClient($http, new InMemoryMotulResponseStore(), new MotulRecommendationParser());

        $client->searchVehicle('mazda 6');
        $client->searchVehicle('mazda 6');

        $this->assertSame(1, $calls);
    }
}
