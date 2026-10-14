<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlannedMaintenance;
use App\Entity\VehicleFact;
use Doctrine\ODM\MongoDB\DocumentManager;

final class McpListPlannedMaintenanceTest extends AppTestCase
{
    use McpClientTrait;

    public function testToolIsDiscoverableAsReadOnly(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        $this->assertArrayHasKey('list_planned_maintenance', $tools, 'the read tool must be discoverable');
        $this->assertNotEmpty($tools['list_planned_maintenance']['title'] ?? null, 'read tool must carry a title');
        $this->assertTrue($tools['list_planned_maintenance']['annotations']['readOnlyHint'] ?? null, 'listing the checklist is read-only');
    }

    public function testReturnsOpenTaskWithItsPartsAndAttributes(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Plan an open task carrying a shopping-list part AND a spec attribute.
        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'type' => 'oil_change',
                'targetUsageValue' => 120000,
                'targetUsageUnit' => 'km',
                'parts' => [['ref' => 'W712/73', 'brand' => 'MANN-FILTER', 'label' => 'oil filter']],
            ],
        ], 3)['result']['structuredContent'];
        $this->assertSame('ok', $plan['status']);

        // Spec attributes stay on a task for catalog/legacy data; no tool writes them onto a task now.
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $dm->clear();
        $stored = $dm->getRepository(PlannedMaintenance::class)->findOneBy(['type' => 'oil_change']);
        $stored->setAttribute(VehicleFact::fromSource('oil_capacity', '4.3', 'web', 'L'));
        $dm->flush();
        $dm->clear();

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_planned_maintenance',
            'arguments' => ['make' => 'MAZDA', 'model' => 'MAZDA6'],
        ], 4)['result']['structuredContent'];

        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $result['tasks'], 'the open task must be listed');

        $task = $result['tasks'][0];
        $this->assertSame('oil_change', $task['type']);
        $this->assertSame('open', $task['status']);
        $this->assertSame(120000, $task['target']['value']);
        $this->assertSame('km', $task['target']['unit']);

        $this->assertCount(1, $task['parts'], 'the shopping-list part must come back');
        $this->assertSame('W712/73', $task['parts'][0]['ref']);
        $this->assertSame('MANN-FILTER', $task['parts'][0]['brand']);

        $this->assertCount(1, $task['attributes'], 'the spec attribute must come back');
        $this->assertSame('oil_capacity', $task['attributes'][0]['key']);
        $this->assertSame('4.3', $task['attributes'][0]['value']);
    }

    public function testListByVehicleIri(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'type' => 'air_filter',
                'parts' => [['ref' => 'C1234']],
            ],
        ], 3);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 4)['result']['structuredContent']['vehicles'][0]['@id'];

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_planned_maintenance',
            'arguments' => ['vehicle' => $iri],
        ], 5)['result']['structuredContent'];

        $this->assertSame('ok', $result['status']);
        $this->assertSame($iri, $result['vehicle']['@id']);
        $this->assertCount(1, $result['tasks']);
        $this->assertSame('air_filter', $result['tasks'][0]['type']);
    }

    public function testDoneAndDismissedTasksAreExcludedFromTheLiveChecklist(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // An open task we then tick off as done (via its PATCH) must drop off the live checklist,
        // mirroring Vehicle::openTasks() (open + actionable only).
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => ['make' => 'RENAULT', 'model' => 'CLIO', 'type' => 'oil_change', 'parts' => [['ref' => 'OC1']]],
        ], 3);
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => ['make' => 'RENAULT', 'model' => 'CLIO', 'type' => 'brakes', 'parts' => [['ref' => 'BR1']]],
        ], 4);

        $vehicleIri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 5)['result']['structuredContent']['vehicles'][0]['@id'];

        $tasks = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_planned_maintenance',
            'arguments' => ['vehicle' => $vehicleIri],
        ], 6)['result']['structuredContent']['tasks'];
        $brakesIri = null;
        foreach ($tasks as $task) {
            if ('brakes' === $task['type']) {
                $brakesIri = $task['@id'];
            }
        }
        $this->assertNotNull($brakesIri, 'each task must surface its dereferenceable @id');

        // Tick the brakes task off.
        $client->request('PATCH', $brakesIri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['status' => 'done'],
        ]);
        $this->assertResponseIsSuccessful();

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_planned_maintenance',
            'arguments' => ['vehicle' => $vehicleIri],
        ], 7)['result']['structuredContent'];

        $this->assertSame(['oil_change'], array_column($result['tasks'], 'type'), 'only the open task remains');
    }

    public function testUnknownVehicleReturnsNoRecords(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_planned_maintenance',
            'arguments' => ['make' => 'NOSUCH', 'model' => 'GHOST', 'vehicleType' => 'car'],
        ], 3);

        $content = $result['result']['structuredContent'];
        $this->assertSame('no_records', $content['status']);
        $this->assertFalse($result['result']['isError'] ?? false);
        $this->assertSame('NOSUCH', $content['vehicle']['make']);
    }

    public function testMissingIdentityIsInvalidInput(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_planned_maintenance',
            'arguments' => ['make' => 'MAZDA'],
        ], 3)['result']['structuredContent'];

        $this->assertSame('invalid_input', $content['status']);
    }
}
