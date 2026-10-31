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
 * The parc listing carries a compact `nextDue` summary so the sidebar rail can show each vehicle's
 * soonest service at a glance — without a per-vehicle forecast fetch.
 */
final class GarageVehicleNextDueTest extends AppTestCase
{
    private function seed(string $make, callable $build): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);

        $vehicle = new Vehicle($make, 'MODEL', VehicleType::Car, 'user');
        $vehicle->setOwner(self::TEST_OWNER);
        $build($vehicle);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
    }

    public function testListingCarriesNextDueSummaryAndIsNullWithNoHistory(): void
    {
        $this->seed('OVERDUEMAKE', static function (Vehicle $v): void {
            // A 12-month oil-change schedule, last done 2018 → overdue regardless of the clock.
            $v->setServiceInterval(ServiceInterval::fromSource('oil_change', MaintenanceAction::Change, 'web', everyMonths: 12));
            new MaintenanceRecord($v, [['type' => MaintenanceType::OilChange->value, 'parts' => []]], new \DateTimeImmutable('2018-01-01'), 'user');
        });
        // No schedule and no history → nothing predictable.
        $this->seed('BLANKMAKE', static function (): void {});

        $client = static::createClient();
        $members = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
        $byMake = array_column($members, null, 'make');

        $this->assertArrayHasKey('OVERDUEMAKE', $byMake);
        $this->assertSame('oil_change', $byMake['OVERDUEMAKE']['nextDue']['type']);
        $this->assertTrue($byMake['OVERDUEMAKE']['nextDue']['overdue']);

        $this->assertNull($byMake['BLANKMAKE']['nextDue'], 'No logged history → nothing predictable to surface.');
    }
}
