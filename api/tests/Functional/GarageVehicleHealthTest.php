<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MaintenanceRecord;
use App\Entity\ServiceInterval;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\MaintenanceType;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * The composed health badge surfaces on the vehicle item GET: status + score + breakdown, derived
 * from the forecast on read. A long-overdue service must read Red; a never-touched vehicle Unknown.
 */
final class GarageVehicleHealthTest extends AppTestCase
{
    private function seed(callable $build): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);

        $vehicle = new Vehicle('TORO', '246-H', VehicleType::GroundsCare, 'user');
        $vehicle->setOwner(self::TEST_OWNER);
        $build($vehicle, $dm);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return $vehicle;
    }

    public function testOverdueServiceReadsRed(): void
    {
        $client = static::createClient();
        $vehicle = $this->seed(static function (Vehicle $vehicle): void {
            // A 12-month oil-change schedule, last done 2018 → overdue regardless of the wall clock.
            $vehicle->setServiceInterval(ServiceInterval::fromSource('oil_change', MaintenanceAction::Change, 'web', everyMonths: 12));
            new MaintenanceRecord($vehicle, [['type' => MaintenanceType::OilChange->value, 'parts' => []]], new \DateTimeImmutable('2018-01-01'), 'user');
        });

        $client->request('GET', '/garage/vehicles/'.(string) $vehicle->getId(), ['headers' => ['Accept' => 'application/ld+json']]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['health' => ['status' => 'red', 'overdue' => 1]]);
    }

    public function testVehicleWithNoScheduleAndNoHistoryReadsUnknown(): void
    {
        $client = static::createClient();
        $vehicle = $this->seed(static function (): void {});

        $client->request('GET', '/garage/vehicles/'.(string) $vehicle->getId(), ['headers' => ['Accept' => 'application/ld+json']]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['health' => ['status' => 'unknown', 'score' => null]]);
    }
}
