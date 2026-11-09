<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

final class GarageOwnerScopingGapsTest extends AppTestCase
{
    private const string OTHER_OWNER = 'someone-else@example.com';

    private function seedVehicle(string $owner): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('RENAULT', 'CLIO', VehicleType::Car, 'user', year: 2004);
        $vehicle->setOwner($owner);
        $dm->persist($vehicle);
        $dm->flush();

        return $vehicle;
    }

    public function testFactCannotBePostedOnAForeignVehicle(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $vehicle = $this->seedVehicle(self::OTHER_OWNER);

        $client->request('POST', '/garage/vehicles/'.(string) $vehicle->getId().'/facts', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['key' => 'oil_type', 'value' => '5W-30'],
        ]);

        $this->assertResponseStatusCodeSame(404);
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->clear();
        $this->assertCount(0, $dm->find(Vehicle::class, (string) $vehicle->getId())->getMaintenanceRecords());
        $this->assertCount(0, $dm->find(Vehicle::class, (string) $vehicle->getId())->getPlannedTasks());
    }

    public function testForeignVehiclePlannedTasksAreNotListed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $vehicle = $this->seedVehicle(self::OTHER_OWNER);
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->persist(new PlannedMaintenance($vehicle, 'oil_change', 'user', parts: [['ref' => 'W 712/75']]));
        $dm->flush();

        $body = $client->request('GET', '/garage/vehicles/'.(string) $vehicle->getId().'/planned-tasks', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertSame([], $body['member'] ?? $body['hydra:member'] ?? []);
    }

    public function testForeignMaintenanceRecordCannotBeRead(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $vehicle = $this->seedVehicle(self::OTHER_OWNER);
        $record = new MaintenanceRecord($vehicle, [['type' => 'oil_change']], new \DateTimeImmutable('2026-01-01'), 'user');
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->persist($record);
        $dm->flush();

        $client->request('GET', '/garage/maintenance-records/'.(string) $record->getId(), ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testOwnRecordAndTasksStillReadable(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $vehicle = $this->seedVehicle(self::TEST_OWNER);
        $record = new MaintenanceRecord($vehicle, [['type' => 'oil_change']], new \DateTimeImmutable('2026-01-01'), 'user');
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->persist($record);
        $dm->persist(new PlannedMaintenance($vehicle, 'oil_change', 'user', parts: [['ref' => 'W 712/75']]));
        $dm->flush();

        $client->request('GET', '/garage/maintenance-records/'.(string) $record->getId(), ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $body = $client->request('GET', '/garage/vehicles/'.(string) $vehicle->getId().'/planned-tasks', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(1, $body['member'] ?? $body['hydra:member']);
    }
}
