<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        $client->request('POST', $iri.'/facts', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
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

    public function testPostFactRejectsBlankKey(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user');
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        $client->request('POST', '/garage/vehicles/'.$vehicle->getId().'/facts', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['key' => '', 'value' => '4.3'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }
}
