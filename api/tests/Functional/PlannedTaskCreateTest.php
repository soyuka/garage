<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * The dashboard's "add a task by hand": POST /garage/vehicles/{vehicleId}/planned-tasks lands an open
 * checklist item (source "user") without an agent turn — the manual counterpart of plan_maintenance.
 */
final class PlannedTaskCreateTest extends AppTestCase
{
    private function seedVehicleIri(): string
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return '/garage/vehicles/'.(string) $vehicle->getId();
    }

    public function testPostCreatesAnOpenTaskOnTheChecklist(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $client->request('POST', $iri.'/planned-tasks', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => [
                'type' => 'brakes',
                'label' => 'plaquettes avant',
                'target' => ['value' => 160000, 'unit' => 'km'],
                'parts' => [['ref' => 'P 85020', 'brand' => 'BREMBO', 'label' => 'Plaquettes']],
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains(['type' => 'brakes', 'label' => 'plaquettes avant', 'status' => 'open']);

        // It shows on the checklist subresource as a real, open, tick-offable task.
        $members = $client->request('GET', $iri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
        $this->assertCount(1, $members);
        $this->assertSame('brakes', $members[0]['type']);
        $this->assertStringStartsWith('/garage/planned-tasks/', $members[0]['@id']);
    }

    public function testPostRejectsBlankType(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri();

        $client->request('POST', $iri.'/planned-tasks', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPostToUnknownVehicleIs404(): void
    {
        $client = static::createClient();

        $client->request('POST', '/garage/vehicles/01ARZ3NDEKTSV4RRFFQ69G5FAV/planned-tasks', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => 'oil_change'],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
