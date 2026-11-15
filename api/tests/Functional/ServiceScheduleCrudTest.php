<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceInterval;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\UsageUnit;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Manual schedule editing from the dashboard: the service-schedule sub-resource on a garage vehicle,
 * mirroring the facts manual-CRUD seam. The agent owns the bulk schedule via set_maintenance_schedule;
 * this is the human editing one rule at a time on the card.
 */
final class ServiceScheduleCrudTest extends AppTestCase
{
    private function seedToro(?callable $build = null): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);

        $vehicle = new Vehicle('TORO', '246-H', VehicleType::GroundsCare, 'user');
        $vehicle->setOwner(self::TEST_OWNER);
        if (null !== $build) {
            $build($vehicle);
        }
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();

        return $vehicle;
    }

    public function testListReturnsTheVehicleRules(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedToro(static function (Vehicle $v): void {
            $v->setServiceInterval(ServiceInterval::fromSource('oil_change', MaintenanceAction::Change, 'web', everyUsageValue: 50, everyUsageUnit: UsageUnit::Hours, firstUsageValue: 5));
        });

        $body = $client->request('GET', '/garage/vehicles/'.(string) $vehicle->getId().'/service-schedule', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        $this->assertResponseIsSuccessful();
        $rules = $body['hydra:member'];
        $this->assertCount(1, $rules);
        $this->assertSame('oil_change', $rules[0]['type']);
        $this->assertSame('change', $rules[0]['action']);
        $this->assertSame(50, $rules[0]['everyUsage']['value']);
        $this->assertSame('hours', $rules[0]['everyUsage']['unit']);
        $this->assertSame(5, $rules[0]['firstUsageValue']);
        $this->assertNotEmpty($rules[0]['id']);
    }

    public function testPostAddsARuleAsUnverifiedUserSource(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedToro();

        $created = $client->request('POST', '/garage/vehicles/'.(string) $vehicle->getId().'/service-schedule', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => 'spark_plug', 'action' => 'replace', 'everyUsageValue' => 100, 'everyUsageUnit' => 'hours'],
        ])->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertSame('spark_plug', $created['type']);
        $this->assertSame('user', $created['source']);
        $this->assertFalse($created['verified'], 'A hand-keyed rule never reads as authoritative.');
    }

    public function testPostRefusesANonWebSourceUrl(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedToro();

        $client->request('POST', '/garage/vehicles/'.(string) $vehicle->getId().'/service-schedule', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => 'spark_plug', 'action' => 'replace', 'everyUsageValue' => 100, 'everyUsageUnit' => 'hours', 'url' => 'javascript://x%0Aalert(1)'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPostUpsertsSameTypeActionComponentInPlace(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedToro(static function (Vehicle $v): void {
            $v->setServiceInterval(ServiceInterval::fromSource('oil_change', MaintenanceAction::Change, 'web', everyUsageValue: 50, everyUsageUnit: UsageUnit::Hours));
        });
        $base = '/garage/vehicles/'.(string) $vehicle->getId().'/service-schedule';

        // Same (type, action) → corrects the cadence in place, no second row.
        $client->request('POST', $base, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['type' => 'oil_change', 'action' => 'change', 'everyUsageValue' => 60, 'everyUsageUnit' => 'hours'],
        ]);
        $this->assertResponseIsSuccessful();

        $rules = $client->request('GET', $base, ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
        $this->assertCount(1, $rules);
        $this->assertSame(60, $rules[0]['everyUsage']['value']);
    }

    public function testDeleteByIdRemovesTheRule(): void
    {
        $client = static::createClient();
        $vehicle = $this->seedToro(static function (Vehicle $v): void {
            $v->setServiceInterval(ServiceInterval::fromSource('air_filter', MaintenanceAction::Clean, 'web', component: 'foam element', everyUsageValue: 25, everyUsageUnit: UsageUnit::Hours));
        });
        $base = '/garage/vehicles/'.(string) $vehicle->getId().'/service-schedule';

        $id = $client->request('GET', $base, ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'][0]['id'];
        $client->request('DELETE', $base.'/'.$id, ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(204);

        $rules = $client->request('GET', $base, ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
        $this->assertCount(0, $rules, 'Tombstoned rule leaves the editable list.');
    }
}
