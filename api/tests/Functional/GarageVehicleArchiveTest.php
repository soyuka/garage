<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;

final class GarageVehicleArchiveTest extends AppTestCase
{
    use McpClientTrait;

    private const PATCH_HEADERS = ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'];

    public function testPatchArchivesKeepsTheDateOnRepeatAndRestores(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicle($client, 'MAZDA', 'MAZDA6');

        $this->assertArrayNotHasKey('archivedAt', $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']])->toArray());

        $archived = $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + self::PATCH_HEADERS,
            'json' => ['archived' => true],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $firstDate = $archived['archivedAt'];
        $this->assertIsString($firstDate);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $firstDate));

        usleep(1_100_000);
        $repeat = $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + self::PATCH_HEADERS,
            'json' => ['archived' => true],
        ])->toArray();
        $this->assertSame($firstDate, $repeat['archivedAt']);

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertJsonContains(['archivedAt' => $firstDate]);

        $restored = $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + self::PATCH_HEADERS,
            'json' => ['archived' => false],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertArrayNotHasKey('archivedAt', $restored);

        $this->assertArrayNotHasKey('archivedAt', $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']])->toArray());
    }

    public function testPatchWithoutArchivedLeavesAnArchivedVehicleArchived(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicle($client, 'MAZDA', 'MAZDA6');
        $client->request('PATCH', $iri, ['headers' => self::authHeaders() + self::PATCH_HEADERS, 'json' => ['archived' => true]]);

        $response = $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + self::PATCH_HEADERS,
            'json' => ['plate' => 'AB-123-CD'],
        ])->toArray();

        $this->assertIsString($response['archivedAt']);
    }

    public function testCollectionKeepsArchivedVehiclesAndExposesArchivedAt(): void
    {
        $client = static::createClient();
        $archivedIri = $this->seedVehicle($client, 'MAZDA', 'MAZDA6');
        $this->seedVehicle($client, 'PEUGEOT', '308');
        $client->request('PATCH', $archivedIri, ['headers' => self::authHeaders() + self::PATCH_HEADERS, 'json' => ['archived' => true]]);

        $members = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];

        $this->assertCount(2, $members);
        $byModel = array_column($members, null, 'model');
        $this->assertIsString($byModel['MAZDA6']['archivedAt']);
        $this->assertArrayNotHasKey('archivedAt', $byModel['308']);
    }

    public function testListVehiclesExcludesArchivedUnlessAsked(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        $iri = $this->seedVehicle($client, 'MAZDA', 'MAZDA6');
        $this->seedVehicle($client, 'PEUGEOT', '308');

        $update = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => ['vehicle' => $iri, 'archived' => true],
        ], 3));
        $this->assertSame('ok', $update['status']);
        $this->assertIsString($update['vehicle']['archivedAt']);

        $default = $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => 'list_vehicles', 'arguments' => []], 4));
        $this->assertSame(1, $default['total']);
        $this->assertSame(['308'], array_column($default['vehicles'], 'model'));

        $all = $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => 'list_vehicles', 'arguments' => ['includeArchived' => true]], 5));
        $this->assertSame(2, $all['total']);
        $byModel = array_column($all['vehicles'], null, 'model');
        $this->assertIsString($byModel['MAZDA6']['archivedAt']);
        $this->assertNull($byModel['308']['archivedAt']);

        $restored = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => ['vehicle' => $iri, 'archived' => false],
        ], 6));
        $this->assertNull($restored['vehicle']['archivedAt']);
    }

    public function testListDueMaintenanceSkipsArchivedVehicles(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        foreach ([3 => 'DAILY', 5 => 'ECO'] as $id => $model) {
            $this->call($client, $sessionId, 'tools/call', [
                'name' => 'set_maintenance_schedule',
                'arguments' => [
                    'make' => 'IVECO', 'model' => $model, 'vehicleType' => 'cv', 'confirmNew' => true,
                    'items' => [['type' => 'oil_change', 'action' => 'change', 'everyMonths' => 12]],
                ],
            ], $id);
            $this->call($client, $sessionId, 'tools/call', [
                'name' => 'log_maintenance',
                'arguments' => [
                    'make' => 'IVECO', 'model' => $model, 'vehicleType' => 'cv',
                    'items' => [['type' => 'oil_change', 'parts' => []]], 'performedAt' => '2018-01-01',
                ],
            ], $id + 1);
        }

        $iri = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];
        $daily = array_column($iri, null, 'model')['DAILY']['@id'];
        $client->request('PATCH', $daily, ['headers' => self::authHeaders() + self::PATCH_HEADERS, 'json' => ['archived' => true]]);

        $due = $this->outcome($this->call($client, $sessionId, 'tools/call', ['name' => 'list_due_maintenance', 'arguments' => []], 9));

        $models = array_unique(array_map(static fn (array $item): string => $item['vehicle']['model'], $due['due']));
        $this->assertSame(['ECO'], array_values($models));
    }

    private function seedVehicle(Client $client, string $make, string $model): string
    {
        $sessionId = $this->initialize($client);
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => ['make' => $make, 'model' => $model, 'vehicleType' => 'car', 'type' => 'oil_change'],
        ], 2);

        $members = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['hydra:member'];

        return array_column($members, null, 'model')[$model]['@id'];
    }
}
