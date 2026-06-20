<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\UsageUnit;
use App\Garage\VehicleType;
use App\Maintenance\PlannedMaintenanceStatus;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Archiving the checklist: POST /garage/vehicles/{vehicleId}/planned-tasks/archive transforms every
 * DONE task into a MaintenanceRecord (an "entretien passé") and files the task away, so the current
 * checklist and the past-maintenance log stay in sync from the done→history direction. Open tasks
 * stay put; dismissed tasks (discarded, never performed) are never recorded.
 */
final class PlannedTaskArchiveTest extends AppTestCase
{
    /** @return array{string, DocumentManager} vehicle IRI + the DM used to seed it */
    private function seedVehicleWithTasks(): array
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);

        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $vehicle->setUsage(142000, UsageUnit::Kilometers);
        $dm->persist($vehicle);

        $now = new \DateTimeImmutable();

        $oil = new PlannedMaintenance(vehicle: $vehicle, type: 'oil_change', source: 'user', label: 'Vidange', owner: self::TEST_OWNER, parts: [['ref' => 'W712/95', 'brand' => 'MANN', 'label' => 'Filtre à huile']]);
        $oil->close(PlannedMaintenanceStatus::Done, $now);
        $dm->persist($oil);

        $brakes = new PlannedMaintenance(vehicle: $vehicle, type: 'brakes', source: 'user', label: 'Plaquettes AV', owner: self::TEST_OWNER);
        $brakes->close(PlannedMaintenanceStatus::Done, $now);
        $dm->persist($brakes);

        $tyres = new PlannedMaintenance(vehicle: $vehicle, type: 'tyres', source: 'user', owner: self::TEST_OWNER);
        $dm->persist($tyres); // stays open

        $wipers = new PlannedMaintenance(vehicle: $vehicle, type: 'wipers', source: 'user', owner: self::TEST_OWNER);
        $wipers->close(PlannedMaintenanceStatus::Dismissed, $now);
        $dm->persist($wipers); // discarded — never recorded

        $dm->flush();
        $dm->clear();

        return ['/garage/vehicles/'.$vehicle->getId(), $dm];
    }

    public function testArchiveTransformsDoneTasksIntoMaintenanceRecords(): void
    {
        $client = static::createClient();
        [$iri] = $this->seedVehicleWithTasks();

        $response = $client->request('POST', $iri.'/planned-tasks/archive', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => (object) [],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(2, $response->toArray()['archived']);

        // The two done tasks are now entretiens passés, stamped with the vehicle's current reading.
        $records = $client->request('GET', $iri.'/maintenance-records', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertCount(2, $records);
        $types = array_column($records, 'type');
        sort($types);
        $this->assertSame(['brakes', 'oil_change'], $types);
        foreach ($records as $record) {
            $this->assertSame(142000, $record['usageValue']);
            $this->assertSame('km', $record['usageUnit']);
            $this->assertSame('user', $record['source']);
        }
    }

    public function testArchivedTasksLeaveTheChecklistWhileOpenTasksStay(): void
    {
        $client = static::createClient();
        [$iri] = $this->seedVehicleWithTasks();

        $client->request('POST', $iri.'/planned-tasks/archive', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => (object) [],
        ]);

        $members = $client->request('GET', $iri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $types = array_column($members, 'type');
        $this->assertContains('tyres', $types, 'the open task remains on the checklist');
        $this->assertNotContains('oil_change', $types, 'an archived task leaves the current checklist');
        $this->assertNotContains('brakes', $types, 'an archived task leaves the current checklist');
    }

    public function testArchiveIsIdempotent(): void
    {
        $client = static::createClient();
        [$iri] = $this->seedVehicleWithTasks();

        $headers = self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'];
        $client->request('POST', $iri.'/planned-tasks/archive', ['headers' => $headers, 'json' => (object) []]);
        $second = $client->request('POST', $iri.'/planned-tasks/archive', ['headers' => $headers, 'json' => (object) []]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $second->toArray()['archived'], 'a re-run archives nothing — no duplicate records');

        $records = $client->request('GET', $iri.'/maintenance-records', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertCount(2, $records);
    }

    public function testDismissedTaskIsNeverRecorded(): void
    {
        $client = static::createClient();
        [$iri] = $this->seedVehicleWithTasks();

        $client->request('POST', $iri.'/planned-tasks/archive', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => (object) [],
        ]);

        $records = $client->request('GET', $iri.'/maintenance-records', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['member'];
        $this->assertNotContains('wipers', array_column($records, 'type'), 'a dismissed task is discarded, never filed as history');
    }

    public function testArchiveOnUnknownVehicleIs404(): void
    {
        $client = static::createClient();

        $client->request('POST', '/garage/vehicles/01ARZ3NDEKTSV4RRFFQ69G5FAV/planned-tasks/archive', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => (object) [],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testArchiveIsOwnerScoped(): void
    {
        $client = static::createClient();
        [$iri] = $this->seedVehicleWithTasks();

        // A different tenant must not archive (nor even see) this owner's vehicle.
        $client->request('POST', $iri.'/planned-tasks/archive', [
            'headers' => self::authHeaders('other-user') + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => (object) [],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
