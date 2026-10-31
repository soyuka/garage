<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * POST /garage/vehicles/{id}/facts records a single user-supplied spec (source "user", unverified)
 * so the dashboard's editable fact rows have a write target — no agent round-trip.
 */
final class GarageVehicleFactTest extends AppTestCase
{
    public function testPostFactRecordsUserSuppliedSpec(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $client->request('POST', $iri.'/facts', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['key' => 'oil_capacity', 'value' => '4.3', 'unit' => 'L'],
        ]);

        $this->assertResponseIsSuccessful();
        // A manually entered spec is unverified and attributed to the user.
        $this->assertJsonContains([
            'facts' => [
                ['key' => 'oil_capacity', 'value' => '4.3', 'unit' => 'L', 'source' => 'user', 'verified' => false],
            ],
        ]);

        // It persists: the next item GET still carries it.
        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertJsonContains([
            'facts' => [['key' => 'oil_capacity', 'value' => '4.3', 'source' => 'user']],
        ]);
    }

    public function testDeleteFactRemovesItFromTheCard(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $client->request('POST', $iri.'/facts', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['key' => 'oil_capacity', 'value' => '4.3', 'unit' => 'L'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('DELETE', $iri.'/facts/oil_capacity', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(204);

        // Gone from the projection.
        $facts = $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']])->toArray()['facts'];
        $this->assertNotContains('oil_capacity', array_column($facts, 'key'), 'the removed fact is off the card');
    }

    public function testDeletingAHistoryDerivedFactSuppressesItFromTheCard(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user');
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        // A logged visit fitted a real oil filter — it projects as the fact oil_filter_ref.
        $record = new MaintenanceRecord(
            $vehicle,
            [['type' => 'oil_filter', 'parts' => [['ref' => 'W712/73', 'brand' => 'MANN-FILTER']]]],
            new \DateTimeImmutable('2026-06-15'),
            'user',
        );
        $dm->persist($record);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $facts = $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']])->toArray()['facts'];
        $this->assertContains('oil_filter_ref', array_column($facts, 'key'), 'the logged part surfaces as a fact first');

        // A history-derived fact has no document to tombstone — DELETE must still remove it (204),
        // not 404, by recording a per-vehicle suppression marker the projection honours.
        $client->request('DELETE', $iri.'/facts/oil_filter_ref', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(204);

        $facts = $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']])->toArray()['facts'];
        $this->assertNotContains('oil_filter_ref', array_column($facts, 'key'), 'the history-derived fact is suppressed off the card');
    }

    public function testDeleteThenReAddFactBringsItBackLive(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user');
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $post = static fn (string $value) => $client->request('POST', $iri.'/facts', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['key' => 'coolant', 'value' => $value],
        ]);

        $post('G12');
        $client->request('DELETE', $iri.'/facts/coolant', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        $post('G13');

        // Re-adding the key after a tombstone lands a fresh, live value — the tombstone never shadows it.
        $this->assertJsonContains(['facts' => [['key' => 'coolant', 'value' => 'G13']]]);
    }

    public function testDeleteUnknownFactIs404(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user');
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        $client->request('DELETE', '/garage/vehicles/'.$vehicle->getId().'/facts/nonexistent', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPostFactRejectsBlankKey(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user');
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        $client->request('POST', '/garage/vehicles/'.$vehicle->getId().'/facts', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['key' => '', 'value' => '4.3'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }
}
