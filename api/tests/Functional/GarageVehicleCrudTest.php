<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * The dashboard's manual garage management, outside the agent's dedup path: create an empty vehicle
 * to fill in by hand, edit every card field (identity included, for a user-owned row), and soft-delete
 * a vehicle so it drops off the parc without erasing its history.
 */
final class GarageVehicleCrudTest extends AppTestCase
{
    private function seedVehicle(string $make = 'MAZDA', string $model = 'MAZDA6'): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $vehicle = new Vehicle($make, $model, VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return $vehicle;
    }

    public function testPostCreatesEmptyVehicleInTheParc(): void
    {
        $client = static::createClient();

        $client->request('POST', '/garage/vehicles', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => (object) [],
        ]);

        $this->assertResponseStatusCodeSame(201);
        // A blank vehicle the user fills in via the inline-editable card.
        $created = $client->getResponse()->toArray();
        $this->assertArrayHasKey('@id', $created);
        $this->assertSame('user', $created['source']);

        $member = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertCount(1, $member, 'the freshly-created vehicle shows in the parc');
    }

    public function testPatchEditsEveryCardField(): void
    {
        $client = static::createClient();
        $iri = '/garage/vehicles/'.$this->seedVehicle()->getId();

        $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => [
                'make' => 'PEUGEOT',
                'model' => '206',
                'year' => 2003,
                'engineVariant' => '1.9 D',
                'engineCode' => 'WJY',
                'kType' => 12345,
                'plate' => 'AB-123-CD',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'make' => 'PEUGEOT',
            'model' => '206',
            'year' => 2003,
            'engineVariant' => '1.9 D',
            'engineCode' => 'WJY',
            'kType' => 12345,
            'plate' => 'AB-123-CD',
        ]);

        // It persists across a fresh read.
        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertJsonContains(['engineCode' => 'WJY', 'engineVariant' => '1.9 D', 'make' => 'PEUGEOT']);
    }

    public function testDeleteSoftRemovesVehicle(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedVehicle();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $client->request('DELETE', $iri, ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(204);

        // Gone from the parc listing…
        $member = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertCount(0, $member, 'a soft-deleted vehicle drops off the parc');

        // …and from its own item read.
        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(404);

        // But the row is only tombstoned, not erased.
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $stored = $dm->getRepository(Vehicle::class)->find((string) $vehicle->getId());
        $this->assertNotNull($stored, 'soft delete keeps the document');
        $this->assertTrue($stored->isDeleted());
    }

    public function testDeletedVehicleNoLongerBlocksReuseOfItsIdentity(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedVehicle();
        $client->request('DELETE', '/garage/vehicles/'.$vehicle->getId(), ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);

        // A new vehicle of the same identity is not shadowed by the tombstoned one.
        $fresh = $this->seedVehicle();
        $this->assertNotSame((string) $vehicle->getId(), (string) $fresh->getId());
        $member = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertCount(1, $member, 'only the live row shows');
    }
}
