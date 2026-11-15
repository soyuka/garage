<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\FixtureHttpClient;

final class VehicleResourceTest extends AppTestCase
{
    public function testPlateLookupReturnsVehicleWithTecdocIds(): void
    {
        $client = static::createClient();
        static::catalogue();
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
        static::catalogue();
        $client->request('GET', '/vehicles/xc-302-zc');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['plate' => 'XC302ZC']);
    }

    public function testUnknownPlateReturns404(): void
    {
        $client = static::createClient();
        static::catalogue();
        $client->request('GET', '/vehicles/ZZ999ZZ');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testBonnerefIsCalledWithTheServiceTokenAndTheOwnerNotTheCallersToken(): void
    {
        $client = static::createClient();
        static::catalogue();
        $client->request('GET', '/vehicles/XC302ZC');

        $this->assertResponseIsSuccessful();
        $sent = static::bonneref()->lastRequest()['headers'];
        $this->assertSame('Bearer '.FixtureHttpClient::SERVICE_TOKEN, $sent['authorization']);
        $this->assertNotSame(self::authHeaders()['Authorization'], $sent['authorization']);
        $this->assertSame(self::TEST_OWNER, $sent['x-bonneref-owner']);
    }
}
