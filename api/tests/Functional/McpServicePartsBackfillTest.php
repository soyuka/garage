<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

final class McpServicePartsBackfillTest extends AppTestCase
{
    use McpClientTrait;

    public function testAMotorbikeLookupOnlyBackfillsTheMotorbikeSharingItsKType(): void
    {
        $client = static::createClient();
        static::bonneref()
            ->route('/cars/101222/part-categories', json_encode(['hydra:member' => [
                ['id' => 200158, 'parentId' => 200047, 'level' => 2, 'name' => 'Oil Filter', 'path' => 'Filters > Oil Filter', 'leaf' => true],
            ]], \JSON_THROW_ON_ERROR), query: ['carType' => 'MC'])
            ->fixture('/cars/101222/part-categories/200158/articles', 'articles_34247_200158');

        $dm = static::getContainer()->get(DocumentManager::class);
        $tasks = [];
        foreach ([VehicleType::Car, VehicleType::Moto] as $type) {
            $vehicle = new Vehicle('BMW', 'Shared kType', $type, 'user');
            $vehicle->setOwner(self::TEST_OWNER);
            $vehicle->setKType(101222);
            $dm->persist($vehicle);
            $tasks[$type->value] = new PlannedMaintenance(vehicle: $vehicle, type: 'oil_filter', source: 'user', owner: self::TEST_OWNER);
            $dm->persist($tasks[$type->value]);
        }
        $dm->flush();
        $dm->clear();

        $sessionId = $this->initialize($client);
        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_service_parts',
            'arguments' => ['carId' => 101222, 'carType' => 'MC', 'service' => 'oil_change'],
        ], 2));
        $this->assertSame('ok', $content['status']);

        $partsOf = static fn (VehicleType $type): array => $dm->find(PlannedMaintenance::class, $tasks[$type->value]->getId())?->getParts() ?? [];
        $this->assertNotSame([], $partsOf(VehicleType::Moto), 'the motorbike task is filled');
        $this->assertSame([], $partsOf(VehicleType::Car), 'a car that merely shares the kType is left alone');
    }
}
