<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Repository\VehicleRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * The identity/listing lookups match make and model case-insensitively. This contract must survive
 * the swap from the index-defeating `^…$/i` regex to a collation-aware equality, so it is pinned
 * here independently of how the case-folding is implemented.
 */
final class VehicleRepositoryTest extends AppTestCase
{
    private function persistVehicle(string $make, string $model): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);

        $vehicle = new Vehicle($make, $model, VehicleType::GroundsCare, 'test');
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return $vehicle;
    }

    private function repository(): VehicleRepository
    {
        $repository = static::getContainer()->get(VehicleRepository::class);
        \assert($repository instanceof VehicleRepository);

        return $repository;
    }

    public function testFindByIdentityMatchesRegardlessOfCase(): void
    {
        static::createClient();
        $stored = $this->persistVehicle('TORO', '246-H');

        $found = $this->repository()->findByIdentity('toro', '246-h', VehicleType::GroundsCare);

        $this->assertNotNull($found);
        $this->assertSame((string) $stored->getId(), (string) $found->getId());
    }

    public function testFindSharedFiltersMakeCaseInsensitively(): void
    {
        static::createClient();
        $this->persistVehicle('TORO', '246-H');

        $found = $this->repository()->findShared('toro');

        $this->assertCount(1, $found);
        $this->assertSame('TORO', $found[0]->getMake());
    }
}
