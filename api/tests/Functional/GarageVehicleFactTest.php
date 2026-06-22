<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Two ways a vehicle's spec facts get filled outside the agent's plan_maintenance path:
 *
 *  - SELF-HEAL: a user-created row carries a kType but no catalog spec sheet (it was added by plate
 *    lookup / update_vehicle, which never fetches fill volumes). The item GET backfills the
 *    catalog fluid specs from upstream so the dashboard answer is no longer "non renseignée".
 *  - MANUAL: POST /garage/vehicles/{id}/facts records a single user-supplied spec (source "user",
 *    unverified) so the dashboard's editable fact rows have a write target — no agent round-trip.
 */
final class GarageVehicleFactTest extends AppTestCase
{
    /** Seed a MAZDA6 exactly as plate lookup leaves it: source "user", kType set, no spec sheet. */
    private function seedUserVehicleWithKType(): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);

        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007, kType: 19107);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return $vehicle;
    }

    public function testItemGetBackfillsCatalogSpecsWhenKTypeKnownButUnfetched(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedUserVehicleWithKType();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);

        $this->assertResponseIsSuccessful();
        // The kType was known all along — the GET pulls the fill volumes from the catalog and the
        // engine-oil capacity/spec surface as verified partsapi facts instead of staying empty.
        $this->assertJsonContains([
            'facts' => [
                ['key' => 'oil_capacity', 'value' => '4.3', 'source' => 'partsapi', 'verified' => true],
            ],
        ]);
    }

    public function testBackfillIsServedFromGarageOnTheSecondRead(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedUserVehicleWithKType();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $callsAfterFirst = self::partsApiCache()->getItem('calls.GetFillVolumes')->get();

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $callsAfterSecond = self::partsApiCache()->getItem('calls.GetFillVolumes')->get();

        $this->assertSame(
            $callsAfterFirst,
            $callsAfterSecond,
            'Once backfilled, the catalog specs are persisted — a second read must not re-fetch upstream.',
        );
    }

    public function testItemGetSurvivesACatalogFailureDuringBackfill(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        // kType 500200 is the fixture sentinel that answers with a 401 error envelope, so the catalog
        // call throws. Self-heal is best-effort enrichment: its failure must NOT 500 the vehicle read
        // — the dashboard still needs the card (just without backfilled facts).
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007, kType: 500200);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        $client->request('GET', '/garage/vehicles/'.$vehicle->getId(), ['headers' => ['Accept' => 'application/ld+json']]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['make' => 'MAZDA', 'facts' => []]);
    }

    public function testPostFactRecordsUserSuppliedSpec(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
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
        \assert($dm instanceof DocumentManager);
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
        \assert($dm instanceof DocumentManager);
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
        \assert($dm instanceof DocumentManager);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user');
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $post = fn (string $value) => $client->request('POST', $iri.'/facts', [
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
        \assert($dm instanceof DocumentManager);
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
        \assert($dm instanceof DocumentManager);
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
