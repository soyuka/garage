<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Vehicle\VehicleIndex;

final class KnownCarTest extends AppTestCase
{
    public function testTheIndexNamesACarFromItsCarId(): void
    {
        $client = static::createClient();
        static::getContainer()->get(VehicleIndex::class)->record(16182, 'PC', 'PEUGEOT', '307 (3A/C)', '1.4', 'catalog');

        $car = $client->request('GET', '/cars/16182/identity?carType=PC', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseIsSuccessful();
        $this->assertSame(['PEUGEOT', '307 (3A/C)', '1.4'], [$car['make'], $car['model'], $car['engine']]);
    }

    public function testACarTheIndexNeverSawIsNotFound(): void
    {
        static::createClient()->request('GET', '/cars/16182/identity?carType=PC', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(404);
    }
}
