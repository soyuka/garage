<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class VehicleResourceTest extends AppTestCase
{
    public function testPlateLookupReturnsVehicleWithTecdocIds(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles/XC302ZC');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'plate' => 'XC302ZC',
            'make' => 'IVECO',
            'model' => 'DAILY',
            'version' => 'CLASSE C',
            'vin' => 'ZCFC298000D531208',
            'energy' => 'DIESEL',
            'carId' => 34247,
            'carType' => 'CV',
            'tecdocManuId' => 55,
            'tecdocModelId' => 9523,
            'engineCode' => 'F1AE0481B',
            'powerKw' => 71,
            'powerHp' => 97,
        ]);
    }

    public function testPlateIsNormalized(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles/xc-302-zc');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['plate' => 'XC302ZC']);
    }

    public function testUnknownPlateReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles/ZZ999ZZ');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testSuccessfulLookupIsCached(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles/XC302ZC');

        $this->assertResponseIsSuccessful();
        $item = self::sivCache()->getItem('XC302ZC');
        $this->assertTrue($item->isHit());

        // Writer must persist the full envelope (prototype_1-compatible), not the bare payload —
        // the reader does $envelope['data'] and a cache hit must round-trip.
        $this->assertArrayHasKey('data', $item->get());

        $client->request('GET', '/vehicles/XC302ZC');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['make' => 'IVECO']);
    }

    public function testLookupIsServedFromCacheWithoutUpstreamCall(): void
    {
        // Seed a plate that has NO fixture: any upstream call would 404 it.
        $pool = self::sivCache();
        $envelope = json_decode((string) file_get_contents(\dirname(__DIR__).'/Fixtures/siv/XC302ZC.json'), true);
        $pool->save($pool->getItem('CACHED99')->set($envelope));

        $client = static::createClient();
        $client->request('GET', '/vehicles/CACHED99');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['make' => 'IVECO']);
    }

    public function testFailedLookupIsNotCached(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles/ZZ999ZZ');

        $this->assertResponseStatusCodeSame(404);
        $this->assertFalse(self::sivCache()->getItem('ZZ999ZZ')->isHit());
    }
}
