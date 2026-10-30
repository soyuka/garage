<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AgentTurn;
use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AssignGarageOwnerCommandTest extends AppTestCase
{
    private const array OWNED = [
        Vehicle::class,
        PlannedMaintenance::class,
        MaintenanceRecord::class,
        VehicleDocument::class,
        AgentTurn::class,
    ];

    public function testMovesEveryOwnedCollectionToTheNewOwner(): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        foreach (self::OWNED as $class) {
            $dm->getDocumentCollection($class)->insertOne(['owner' => 'admin@example.com']);
        }

        $tester = new CommandTester((new Application(static::$kernel))->find('app:garage:assign-owner'));
        $tester->execute(['owner' => 'soyuka@example.com', '--from' => 'admin@example.com']);

        self::assertSame(0, $tester->getStatusCode());
        foreach (self::OWNED as $class) {
            $collection = $dm->getDocumentCollection($class);
            self::assertSame(0, $collection->countDocuments(['owner' => 'admin@example.com']), $class);
            self::assertSame(1, $collection->countDocuments(['owner' => 'soyuka@example.com']), $class);
        }
    }
}
