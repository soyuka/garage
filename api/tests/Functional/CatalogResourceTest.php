<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class CatalogResourceTest extends AppTestCase
{
    public function testListMakes(): void
    {
        $client = static::createClient();
        static::catalogue();
        $client->request('GET', '/makes');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'hydra:totalItems' => 4,
            'hydra:member' => [
                ['id' => 3854, 'name' => 'ABARTH'],
                ['id' => 55, 'name' => 'IVECO'],
                ['id' => 93, 'name' => 'RENAULT'],
                ['id' => 72, 'name' => 'MAZDA'],
            ],
        ]);
    }

    public function testListMakesForwardsTheSearchAndTheCallersToken(): void
    {
        $client = static::createClient();
        static::bonneref()->route('/makes', json_encode(['hydra:member' => [['id' => 55, 'name' => 'IVECO']]], \JSON_THROW_ON_ERROR));
        $response = $client->request('GET', '/makes?search=ive&carType=CV');

        $this->assertResponseIsSuccessful();
        $this->assertSame('IVECO', $response->toArray()['hydra:member'][0]['name']);

        $sent = static::bonneref()->lastRequest();
        $this->assertSame(['carType' => 'CV', 'search' => 'ive'], $sent['query']);
        $this->assertSame(self::authHeaders()['Authorization'], $sent['headers']['authorization']);
    }

    public function testListModelsReturnsTheMazdaLines(): void
    {
        $client = static::createClient();
        static::catalogue();
        $response = $client->request('GET', '/makes/72/models?carType=PC');

        $this->assertResponseIsSuccessful();

        $members = $response->toArray()['hydra:member'];
        $row = array_values(array_filter($members, static fn (array $m): bool => 4887 === $m['id']))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(['name' => '6 Hatchback (GG)', 'yearFrom' => '2002-08', 'yearTo' => '2008-12'], array_intersect_key($row, array_flip(['name', 'yearFrom', 'yearTo'])));
        $keys = array_map(static fn (array $m): string => $m['id'].'|'.$m['name'], $members);
        $this->assertSame($keys, array_values(array_unique($keys)), 'rows are deduplicated on modelId + modelName');
        $this->assertCount(1, array_filter($members, static fn (array $m): bool => 6987 === $m['id'] && '6 ULTRA A trois volumes (GH)' === $m['name']));
    }

    public function testListCarsReturnsTheMazda6Variants(): void
    {
        $client = static::createClient();
        static::catalogue();
        $response = $client->request('GET', '/makes/72/models/4887/cars?carType=PC');

        $this->assertResponseIsSuccessful();
        $this->assertSame(14, $response->toArray()['hydra:totalItems']);
        $this->assertJsonContains([
            'hydra:member' => [
                ['id' => 16680, 'name' => '1.8', 'capacityCc' => 1798],
                [
                    'id' => 16681,
                    'name' => '2.0 (GGES)',
                    'fullName' => 'MAZDA 6 Hatchback (GG) 2.0 (GGES)',
                    'body' => 'Hatchback',
                    'engineType' => 'Petrol Engine',
                    'capacityCc' => 1999,
                    'powerKw' => 104,
                    'powerPs' => 141,
                    'yearFrom' => '2002-08',
                    'yearTo' => '2007-08',
                ],
            ],
        ]);
    }

    public function testListCarsLeavesTheFiltersToBonneref(): void
    {
        $client = static::createClient();
        static::catalogue();
        $client->request('GET', '/makes/72/models/4887/cars?carType=PC&year=2005&capacityLitres=2.0&fuel=diesel&search=gg');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['carType' => 'PC', 'year' => '2005', 'capacityLitres' => '2', 'fuel' => 'diesel', 'search' => 'gg'],
            static::bonneref()->lastRequest()['query'],
        );
    }
}
