<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Finder\FinderRenderTools;
use App\Vehicle\VehicleIndex;

final class VehicleIndexTest extends AppTestCase
{
    public function testBrowsingCarsIndexesEveryRow(): void
    {
        $client = static::createClient();

        $client->request('GET', '/makes/72/models/4887/cars?carType=PC');
        self::assertResponseIsSuccessful();

        $car = $this->index()->find(16681, 'PC');
        self::assertNotNull($car);
        self::assertSame(['MAZDA', '6 Hatchback (GG)', '2.0 (GGES)', 'MAZDA 6 Hatchback (GG) 2.0 (GGES)', 'catalog'], [$car['make'], $car['model'], $car['engine'], $car['fullName'], $car['source']]);
        self::assertSame('2002-08', $car['yearFrom']);
        self::assertNotNull($this->index()->find(19107, 'PC'));
        self::assertNull($this->index()->find(16681, 'CV'));
    }

    public function testACachedCarsReadIndexesToo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/makes/72/models/4887/cars?carType=PC');
        $this->index()->forget(16681, 'PC');
        self::assertNull($this->index()->find(16681, 'PC'));

        $client->request('GET', '/makes/72/models/4887/cars?carType=PC');

        self::assertNotNull($this->index()->find(16681, 'PC'));
    }

    public function testAPlateLookupIndexesItsTecdocCar(): void
    {
        static::createClient()->request('GET', '/vehicles/XC302ZC');
        self::assertResponseIsSuccessful();

        $car = $this->index()->find(34247, 'CV');
        self::assertSame(['IVECO', 'DAILY', 'CLASSE C · F1AE0481B', 'siv'], [$car['make'], $car['model'], $car['engine'], $car['source']]);
    }

    public function testALowerPrioritySourceNeverOverwritesTheCatalogueName(): void
    {
        $client = static::createClient();
        $client->request('GET', '/makes/72/models/4887/cars?carType=PC');

        $this->index()->record(16681, 'PC', 'MAZDA', 'MAZDA6', 'whatever', 'siv');

        self::assertSame('6 Hatchback (GG)', $this->index()->find(16681, 'PC')['model']);
    }

    public function testSetVehicleIndexesTheCarTheAgentIdentified(): void
    {
        static::bootKernel();
        static::getContainer()->get(FinderRenderTools::class)->setVehicle(9586, 'PC', 'PEUGEOT', '307', '1.6 HDi', 'XB-301-ZB');

        $car = $this->index()->find(9586, 'PC');
        self::assertSame(['PEUGEOT', '307', '1.6 HDi', 'finder'], [$car['make'], $car['model'], $car['engine'], $car['source']]);
    }

    public function testThePageVehicleHeaderIndexesTheCarItDescribes(): void
    {
        $header = rawurlencode((string) json_encode(['plate' => null, 'carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6', 'engine' => '2.0 · LF']));

        static::createClient()->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['X-Finder-Vehicle' => $header],
        ]);
        self::assertResponseIsSuccessful();

        $car = $this->index()->find(19107, 'PC');
        self::assertSame(['MAZDA', '6', '2.0 · LF'], [$car['make'], $car['model'], $car['engine']]);
    }

    private function index(): VehicleIndex
    {
        return static::getContainer()->get(VehicleIndex::class);
    }
}
