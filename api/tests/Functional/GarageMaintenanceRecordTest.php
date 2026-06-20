<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Past maintenance on the dashboard: a vehicle's service history is a read collection
 * (GET /garage/vehicles/{vehicleId}/maintenance-records), and the user can log a past service by
 * hand (POST the same route) — the manual counterpart of the agent's log_maintenance.
 */
final class GarageMaintenanceRecordTest extends AppTestCase
{
    private function seedVehicleIri(): string
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return '/garage/vehicles/'.$vehicle->getId();
    }

    public function testPostThenListReturnsTheHistory(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $client->request('POST', $iri.'/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => [
                'type' => 'oil_change',
                'performedAt' => '2025-09-12',
                'usageValue' => 138000,
                'usageUnit' => 'km',
                'notes' => 'Vidange + filtre',
                'cost' => 89.9,
                'currency' => 'EUR',
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains(['type' => 'oil_change', 'performedAt' => '2025-09-12', 'usageValue' => 138000]);

        $members = $client->request('GET', $iri.'/maintenance-records', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertCount(1, $members);
        $this->assertSame('oil_change', $members[0]['type']);
        $this->assertStringStartsWith('/garage/maintenance-records/', $members[0]['@id']);
    }

    public function testHistoryIsNewestFirst(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        foreach (['2024-01-10', '2025-06-01'] as $date) {
            $client->request('POST', $iri.'/maintenance-records', [
                'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
                'json' => ['type' => 'oil_change', 'performedAt' => $date],
            ]);
        }

        $members = $client->request('GET', $iri.'/maintenance-records', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertCount(2, $members);
        $this->assertSame('2025-06-01', $members[0]['performedAt'], 'newest service first');
    }

    public function testPostRejectsBlankType(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $client->request('POST', $iri.'/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => '', 'performedAt' => '2025-01-01'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPostToUnknownVehicleIs404(): void
    {
        $client = static::createClient();

        $client->request('POST', '/garage/vehicles/01ARZ3NDEKTSV4RRFFQ69G5FAV/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => 'oil_change', 'performedAt' => '2025-01-01'],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
