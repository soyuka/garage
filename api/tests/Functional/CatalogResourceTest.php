<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class CatalogResourceTest extends AppTestCase
{
    public function testListMakes(): void
    {
        $client = static::createClient();
        $client->request('GET', '/makes');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'totalItems' => 3,
            'member' => [
                ['id' => 3854, 'name' => 'ABARTH'],
                ['id' => 55, 'name' => 'IVECO'],
                ['id' => 93, 'name' => 'RENAULT'],
            ],
        ]);
        // Cache key signature must stay stable with the already-paid live response
        $this->assertTrue(self::partsApiCache()->getItem('getMakes.8dfd0ff78534b11449383e60daa7f02a758cdf60')->isHit());
    }

    public function testListModelsAnswers501UntilTokenIsProvided(): void
    {
        $client = static::createClient();
        $client->request('GET', '/makes/55/models');

        $this->assertResponseStatusCodeSame(501);
        $this->assertJsonContains(['detail' => 'No partsapi.ru API key configured for method "getModels" (env PARTSAPI_KEY_getModels).']);
    }

    public function testListCarsAnswers501UntilTokenIsProvided(): void
    {
        $client = static::createClient();
        $client->request('GET', '/makes/55/models/9523/cars');

        $this->assertResponseStatusCodeSame(501);
        $this->assertJsonContains(['detail' => 'No partsapi.ru API key configured for method "getCars" (env PARTSAPI_KEY_getCars).']);
    }
}
