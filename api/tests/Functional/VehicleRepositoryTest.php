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

        $vehicle = new Vehicle($make, $model, VehicleType::GroundsCare, 'test');
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return $vehicle;
    }

    private function repository(): VehicleRepository
    {
        $repository = static::getContainer()->get(VehicleRepository::class);

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

    public function testFindSharedOrdersMostRecentlyTouchedFirst(): void
    {
        static::createClient();
        // OLD touched yesterday, NEW touched today, NEVER never touched (null updatedAt sorts last).
        $this->persistTouched('TORO', 'OLD', new \DateTimeImmutable('2026-01-01'));
        $this->persistTouched('TORO', 'NEW', new \DateTimeImmutable('2026-06-01'));
        $this->persistVehicle('TORO', 'NEVER');

        $models = array_map(static fn (Vehicle $v): string => $v->getModel(), $this->repository()->findShared());

        $this->assertSame(['NEW', 'OLD', 'NEVER'], $models);
    }

    public function testFindSharedPaginates(): void
    {
        static::createClient();
        $this->persistTouched('TORO', 'A', new \DateTimeImmutable('2026-06-03'));
        $this->persistTouched('TORO', 'B', new \DateTimeImmutable('2026-06-02'));
        $this->persistTouched('TORO', 'C', new \DateTimeImmutable('2026-06-01'));

        $repo = $this->repository();
        $this->assertSame(3, $repo->countShared());

        $page1 = array_map(static fn (Vehicle $v): string => $v->getModel(), $repo->findShared(null, null, null, 2, 0));
        $page2 = array_map(static fn (Vehicle $v): string => $v->getModel(), $repo->findShared(null, null, null, 2, 2));

        $this->assertSame(['A', 'B'], $page1);
        $this->assertSame(['C'], $page2);
    }

    private function persistTouched(string $make, string $model, \DateTimeImmutable $at): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);

        $vehicle = new Vehicle($make, $model, VehicleType::GroundsCare, 'test');
        $vehicle->setOwner(self::TEST_OWNER);
        $vehicle->touch($at);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return $vehicle;
    }
}
