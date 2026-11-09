<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Garage\VehicleWriteResolver;
use Doctrine\ODM\MongoDB\DocumentManager;

final class DeletedVehicleWriteTest extends AppTestCase
{
    use McpClientTrait;

    public function testAWriteAddressedToADeletedVehicleIsNotFound(): void
    {
        $client = static::createClient();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $vehicle->delete();
        $dm->persist($vehicle);
        $dm->flush();
        $sessionId = $this->initialize($client);

        $outcome = $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => 'log_maintenance', 'arguments' => [
            'vehicle' => '/garage/vehicles/'.$vehicle->getId(),
            'items' => [['type' => 'oil_change']],
        ]], 3));

        $this->assertSame('not_found', $outcome['error'] ?? null, json_encode($outcome, \JSON_THROW_ON_ERROR));
        $this->assertSame(0, $dm->getRepository(MaintenanceRecord::class)->createQueryBuilder()->count()->getQuery()->execute());
    }

    public function testTheWriteResolverRefusesADeletedVehicleById(): void
    {
        static::bootKernel();
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007);
        $vehicle->setOwner(self::TEST_OWNER);
        $vehicle->delete();
        $dm->persist($vehicle);
        $dm->flush();

        $resolution = static::getContainer()->get(VehicleWriteResolver::class)->resolveForWrite(
            $vehicle->getId(), null, null, null, VehicleType::Car, null, null, null, false, 'user',
        );

        $this->assertNull($resolution->vehicle);
        $this->assertSame((string) $vehicle->getId(), $resolution->vehicleId);
    }
}
