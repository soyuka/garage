<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Repository\VehicleDocumentRepository;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Without an owner in context a read must refuse, not match `owner: null`: legacy unowned documents
 * would otherwise be served to whichever code path lost its principal.
 */
final class OwnerlessReadTest extends AppTestCase
{
    public function testAVehicleReadWithoutAnOwnerRefusesInsteadOfMatchingUnownedRows(): void
    {
        static::createClient();
        $legacy = $this->persistUnowned();

        $this->expectException(\LogicException::class);
        new VehicleRepository($this->registry(), $this->ownerless())->findOwned((string) $legacy->getId());
    }

    public function testAListingWithoutAnOwnerRefuses(): void
    {
        static::createClient();
        $this->persistUnowned();

        $this->expectException(\LogicException::class);
        new VehicleRepository($this->registry(), $this->ownerless())->findShared();
    }

    public function testADocumentReadWithoutAnOwnerRefuses(): void
    {
        static::createClient();
        $legacy = $this->persistUnowned();

        $this->expectException(\LogicException::class);
        new VehicleDocumentRepository($this->registry(), $this->ownerless())->findForVehicle($legacy);
    }

    private function persistUnowned(): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('TORO', '246-H', VehicleType::GroundsCare, 'test');
        $dm->persist($vehicle);
        $dm->flush();

        return $vehicle;
    }

    private function registry(): ManagerRegistry
    {
        $registry = static::getContainer()->get('doctrine_mongodb');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function ownerless(): OwnerContext
    {
        return new OwnerContext(static::getContainer()->get(Security::class));
    }
}
