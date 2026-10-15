<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Tests\Mock\RecordingHub;
use Doctrine\ODM\MongoDB\DocumentManager;

final class GarageVehicleSaveTest extends AppTestCase
{
    private const array PEUGEOT = ['make' => 'PEUGEOT', 'model' => '307', 'carId' => 9586, 'plate' => 'XB-301-ZB'];

    public function testCreatesTheVehicle(): void
    {
        $client = static::createClient();

        $body = $this->save($client, self::PEUGEOT);

        $this->assertResponseStatusCodeSame(201);
        $this->assertMatchesRegularExpression('~^/garage/vehicles/[0-9A-HJKMNP-TV-Z]{26}$~', $body['@id']);
        $this->assertSame(['PEUGEOT', '307', 9586, 'XB-301-ZB'], [$body['make'], $body['model'], $body['kType'], $body['plate']]);
        $this->assertNotSame([], array_filter(static::getContainer()->get(RecordingHub::class)->updates, static fn ($u): bool => str_contains($u->getData(), '"kind":"vehicle"')));
    }

    public function testTheSamePlateMatchesTheStoredVehicle(): void
    {
        $client = static::createClient();
        $first = $this->save($client, self::PEUGEOT);

        $second = $this->save($client, ['plate' => 'xb 301 zb'] + self::PEUGEOT);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($first['@id'], $second['@id']);
        $this->assertCount(1, $this->garage($client));
    }

    public function testAnotherPlateOnTheSameModelIsAnotherCar(): void
    {
        $client = static::createClient();
        $first = $this->save($client, self::PEUGEOT);

        $second = $this->save($client, ['plate' => 'GH-222-JK'] + self::PEUGEOT);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNotSame($first['@id'], $second['@id']);
        $this->assertCount(2, $this->garage($client));
    }

    public function testNoPlateAndAKnownCarIdIsAmbiguous(): void
    {
        $client = static::createClient();
        $first = $this->save($client, self::PEUGEOT);

        $body = $this->save($client, ['make' => 'PEUGEOT', 'model' => '307 SW', 'carId' => 9586]);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('ambiguous_match', $body['status']);
        $this->assertIsString($body['reason']);
        $this->assertSame([$first['@id']], array_column($body['candidates'], '@id'));
        $this->assertCount(1, $this->garage($client));

        $this->save($client, ['make' => 'PEUGEOT', 'model' => '307 SW', 'carId' => 9586, 'confirmNew' => true]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testAVehicleIriEnrichesThatRow(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $stored = new Vehicle('PEUGEOT', '307', VehicleType::Car, 'user');
        $stored->setOwner(self::TEST_OWNER);
        $dm->persist($stored);
        $dm->flush();

        $body = $this->save($client, ['vehicle' => '/garage/vehicles/'.$stored->getId()] + self::PEUGEOT);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('/garage/vehicles/'.$stored->getId(), $body['@id']);
        $this->assertSame([9586, 'XB-301-ZB'], [$body['kType'], $body['plate']]);
    }

    public function testAnUnknownVehicleIriIs404(): void
    {
        $client = static::createClient();

        $this->save($client, ['vehicle' => '/garage/vehicles/01ARZ3NDEKTSV4RRFFQ69G5FAV']);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMakeAndModelAreRequiredWithoutAVehicle(): void
    {
        $client = static::createClient();

        $this->save($client, ['plate' => 'XB-301-ZB']);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testAnonymousIsRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/garage/vehicles/save', ['headers' => ['Content-Type' => 'application/ld+json'], 'json' => self::PEUGEOT]);

        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function save(object $client, array $payload): array
    {
        return $client->request('POST', '/garage/vehicles/save', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => $payload,
        ])->toArray(false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function garage(object $client): array
    {
        return $client->request('GET', '/garage/vehicles', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
    }
}
