<?php

declare(strict_types=1);

namespace App\Tests\Unit\Motul;

use App\Motul\MotulClient;
use App\Motul\MotulRecommendationParser;
use App\Motul\MotulVehicle;
use App\Tests\Support\InMemoryMotulResponseStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MotulClientTest extends TestCase
{
    private static function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../../Fixtures/motul/'.$name);
        self::assertNotFalse($contents);

        return $contents;
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
        $matches = array_values(array_filter($vehicles, static fn (MotulVehicle $v): bool => '9950' === $v->id));
        $this->assertCount(1, $matches);
        $this->assertSame('6', $matches[0]->model);
        $this->assertTrue($matches[0]->coversYear(2004));
        $this->assertFalse($matches[0]->coversYear(2010));
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
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::fixture('search-vehicle_mazda6.json'));
        });
        $client = new MotulClient($http, new InMemoryMotulResponseStore(), new MotulRecommendationParser());

        $client->searchVehicle('mazda 6');
        $client->searchVehicle('mazda 6');

        $this->assertSame(1, $calls);
    }
}
