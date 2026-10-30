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
        $makes = array_column($client->getResponse()->toArray()['hydra:member'], 'name', 'id');
        $this->assertSame('ABARTH', $makes[3854]);
        $this->assertSame('IVECO', $makes[55]);
        $this->assertSame('RENAULT', $makes[93]);
        $this->assertSame('MAZDA', $makes[72]);
        $names = array_values($makes);
        $sorted = $names;
        usort($sorted, strcasecmp(...));
        $this->assertSame($sorted, $names, 'makes are listed by name');
    }

    public function testListMakesFilteredBySearch(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/makes?search=ive');

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $response->toArray()['hydra:totalItems']);
        $this->assertSame('IVECO', $response->toArray()['hydra:member'][0]['name']);
    }

    public function testListModelsReturnsTheMazdaLines(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/makes/72/models?carType=PC');

        $this->assertResponseIsSuccessful();

        $members = $response->toArray()['hydra:member'];
        $row = array_values(array_filter($members, static fn (array $m): bool => 4887 === $m['id']))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(['name' => '6 Hatchback (GG)', 'yearFrom' => '2002-08', 'yearTo' => '2008-12'], array_intersect_key($row, array_flip(['name', 'yearFrom', 'yearTo'])));
        $keys = array_map(static fn (array $m): string => $m['id'].'|'.$m['name'], $members);
        $this->assertSame($keys, array_values(array_unique($keys)), 'rows are deduplicated on modelId + modelName');
        $this->assertCount(1, array_filter($members, static fn (array $m): bool => 6987 === $m['id'] && '6 A trois volumes (GH)' === $m['name']));
    }

    public function testListCarsReturnsTheMazda6Variants(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/makes/72/models/4887/cars?carType=PC');

        $this->assertResponseIsSuccessful();
        $this->assertSame(13, $response->toArray()['hydra:totalItems']);
        $this->assertJsonContains([
            'hydra:member' => [
                ['id' => 16680, 'name' => '1.8 (L813)', 'capacityCc' => 1798],
                [
                    'id' => 16681,
                    'name' => '2.0 (LF17/LF18)',
                    'fullName' => 'MAZDA 6 Hatchback (GG) 2.0 (LF17/LF18)',
                    'body' => '3/5 portes',
                    'engineType' => 'Essence',
                    'capacityCc' => 1999,
                    'powerKw' => 104,
                    'powerPs' => 141,
                    'yearFrom' => '2002-08',
                    'yearTo' => '2007-08',
                ],
            ],
        ]);
    }

    public function testListCarsFiltersByYearAndCapacity(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/makes/72/models/4887/cars?carType=PC&year=2005&capacityLitres=2.0');

        $this->assertResponseIsSuccessful();
        $ids = array_column($response->toArray()['hydra:member'], 'id');
        sort($ids);
        $this->assertSame([16681, 16683, 16844, 18827, 19107], $ids);
    }

    public function testListCarsFiltersByFuel(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/makes/72/models/4887/cars?carType=PC&year=2005&capacityLitres=2.0&fuel=diesel');

        $this->assertResponseIsSuccessful();
        $ids = array_column($response->toArray()['hydra:member'], 'id');
        sort($ids);
        $this->assertSame([16683, 16844, 18827], $ids);
    }
}
