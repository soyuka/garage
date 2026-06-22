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
        $this->assertJsonContains(['items' => [['type' => 'oil_change', 'parts' => []]], 'performedAt' => '2025-09-12', 'usageValue' => 138000]);

        $members = $client->request('GET', $iri.'/maintenance-records', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertCount(1, $members);
        $this->assertSame('oil_change', $members[0]['items'][0]['type']);
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

    public function testPostWithMultipleItemsAndParts(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $client->request('POST', $iri.'/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => [
                'label' => 'Entretien complet',
                'performedAt' => '2025-09-12',
                'usageValue' => 138000,
                'usageUnit' => 'km',
                'items' => [
                    ['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'brand' => 'Motul', 'label' => 'Huile 5W30', 'qty' => 5]]],
                    ['type' => 'oil_filter', 'parts' => [['ref' => 'W712/52', 'brand' => 'MANN']]],
                    ['type' => 'inspection'],
                ],
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            'label' => 'Entretien complet',
            'items' => [
                ['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'brand' => 'Motul', 'label' => 'Huile 5W30', 'qty' => 5]]],
                ['type' => 'oil_filter', 'parts' => [['ref' => 'W712/52', 'brand' => 'MANN']]],
                ['type' => 'inspection', 'parts' => []],
            ],
        ]);
    }

    public function testPostStoresPerPartPriceAsCents(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $client->request('POST', $iri.'/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => [
                'performedAt' => '2025-09-12',
                'items' => [
                    ['type' => 'oil_change', 'parts' => [
                        ['ref' => '5W30', 'brand' => 'Motul', 'qty' => 5, 'price' => 12.5],
                        ['ref' => 'W712/52', 'brand' => 'MANN'],
                    ]],
                ],
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        // Price entered in euros is stored as integer minor units, like the visit-level cost; a part
        // with no price stays priceless (no key) rather than 0.
        $this->assertJsonContains([
            'items' => [
                ['type' => 'oil_change', 'parts' => [
                    ['ref' => '5W30', 'brand' => 'Motul', 'qty' => 5, 'priceCents' => 1250],
                    ['ref' => 'W712/52', 'brand' => 'MANN'],
                ]],
            ],
        ]);
        $part = $client->getResponse()->toArray()['items'][0]['parts'][1];
        $this->assertArrayNotHasKey('priceCents', $part, 'a part with no price carries no priceCents');
    }

    public function testPatchReplacesItemsAndVisitFields(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $created = $client->request('POST', $iri.'/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => 'oil_change', 'performedAt' => '2025-09-12', 'cost' => 50.0],
        ])->toArray();

        $client->request('PATCH', $created['@id'], [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => [
                'label' => 'Révision corrigée',
                'cost' => 120.5,
                'items' => [
                    ['type' => 'brakes', 'parts' => [['ref' => 'BRK-1', 'brand' => 'Brembo']]],
                    ['type' => 'brake_fluid'],
                ],
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'label' => 'Révision corrigée',
            'costCents' => 12050,
            'items' => [
                ['type' => 'brakes', 'parts' => [['ref' => 'BRK-1', 'brand' => 'Brembo']]],
                ['type' => 'brake_fluid', 'parts' => []],
            ],
        ]);
    }

    public function testPatchUpdatesPerPartPrice(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $created = $client->request('POST', $iri.'/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['performedAt' => '2025-09-12', 'items' => [
                ['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'price' => 12.5]]],
            ]],
        ])->toArray();
        $this->assertSame(1250, $created['items'][0]['parts'][0]['priceCents']);

        $client->request('PATCH', $created['@id'], [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['items' => [
                ['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'price' => 14.9]]],
            ]],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['items' => [['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'priceCents' => 1490]]]]]);
    }

    public function testDeleteRemovesRecord(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $created = $client->request('POST', $iri.'/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => 'oil_change', 'performedAt' => '2025-09-12'],
        ])->toArray();

        $client->request('DELETE', $created['@id'], ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(204);

        $members = $client->request('GET', $iri.'/maintenance-records', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertCount(0, $members);
    }

    public function testPatchForeignRecordIs404(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $created = $client->request('POST', $iri.'/maintenance-records', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => 'oil_change', 'performedAt' => '2025-09-12'],
        ])->toArray();

        $client->request('PATCH', $created['@id'], [
            'headers' => self::authHeaders('intruder') + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['label' => 'hijack'],
        ]);
        $this->assertResponseStatusCodeSame(404);

        $client->request('DELETE', $created['@id'], ['headers' => self::authHeaders('intruder') + ['Accept' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(404);
    }
}
