<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MaintenanceRecord;
use App\Entity\ServiceInterval;
use App\Entity\Vehicle;
use App\Garage\UsageUnit as GarageUsageUnit;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\MaintenanceType;
use App\Maintenance\UsageUnit;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * GET /garage/vehicles/{id}/forecast — the per-vehicle forecast the dashboard renders: the
 * schedule-driven due items (usage axis) plus the honest gaps (known cadence, no baseline).
 */
final class VehicleForecastTest extends AppTestCase
{
    private function seedToro(): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);

        $vehicle = new Vehicle('TORO', '246-H', VehicleType::GroundsCare, 'user');
        $vehicle->setOwner(self::TEST_OWNER);
        // Oil every 50 h (schedule), air filter every 100 h (no baseline → gap).
        $vehicle->setServiceInterval(ServiceInterval::fromSource('oil_change', MaintenanceAction::Change, 'web', everyUsageValue: 50, everyUsageUnit: UsageUnit::Hours));
        $vehicle->setServiceInterval(ServiceInterval::fromSource('air_filter', MaintenanceAction::Replace, 'web', everyUsageValue: 100, everyUsageUnit: UsageUnit::Hours));
        // One oil change at 150 h — the baseline; the vehicle's current reading is the same.
        new MaintenanceRecord($vehicle, [['type' => MaintenanceType::OilChange->value, 'parts' => []]], new \DateTimeImmutable('2026-05-01'), 'user', usageValue: 150, usageUnit: UsageUnit::Hours);
        $vehicle->setUsage(150, GarageUsageUnit::Hours);

        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return $vehicle;
    }

    public function testForecastSurfacesScheduleDrivenUsageAxisAndGaps(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedToro();

        $body = $client->request('GET', '/garage/vehicles/'.(string) $vehicle->getId().'/forecast', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertSame('/garage/vehicles/'.(string) $vehicle->getId(), $body['vehicle']);

        $oil = array_values(array_filter($body['due'], static fn (array $d): bool => 'oil_change' === $d['type']));
        $this->assertCount(1, $oil);
        $this->assertSame('schedule', $oil[0]['rule']);
        $this->assertSame('change', $oil[0]['action']);
        $this->assertSame(200, $oil[0]['usage']['due']);
        $this->assertSame(50, $oil[0]['usage']['remaining']);
        $this->assertSame('hours', $oil[0]['usage']['unit']);

        // The forecast item is per-vehicle: it does not repeat the vehicle identity block.
        $this->assertArrayNotHasKey('vehicle', $oil[0]);

        $this->assertContains('air_filter', $body['gaps'], 'Known cadence, no logged baseline → an honest gap.');
    }

    public function testUnknownVehicleIs404(): void
    {
        $client = static::createClient();

        $client->request('GET', '/garage/vehicles/01ARZ3NDEKTSV4RRFFQ69G5FAV/forecast', ['headers' => ['Accept' => 'application/ld+json']]);

        $this->assertResponseStatusCodeSame(404);
    }
}
