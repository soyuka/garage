<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class VehiclePlannedTasksTest extends AppTestCase
{
    use McpClientTrait;

    public function testVehicleExposesItsOpenChecklistAsASubresource(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'type' => 'oil_change',
            ],
        ], 3)['result']['structuredContent'];

        $vehicleIri = $plan['task']['vehicle'];

        // The checklist lives on its own dereferenceable route — a vehicle-scoped collection of
        // PlannedTask resources, not an embedded blob on the vehicle item.
        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $members = $body['member'] ?? $body['hydra:member'] ?? [];
        $this->assertCount(1, $members, 'the subresource must list the open task');
        $this->assertSame('oil_change', $members[0]['type'] ?? null);
        $this->assertStringStartsWith('/garage/planned-tasks/', $members[0]['@id'] ?? '', 'each task carries its own tick-off IRI');
        $this->assertArrayHasKey('parts', $members[0], 'the shopping list rides each task');
    }

    public function testDismissedTasksAreHiddenByDefaultButSurfacedOnDemand(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'type' => 'timing_belt',
                'label' => 'courroie de distribution',
            ],
        ], 3)['result']['structuredContent'];

        $vehicleIri = $plan['task']['vehicle'];

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'dismiss_maintenance',
            'arguments' => ['vehicle' => $vehicleIri, 'type' => 'timing_belt', 'label' => 'courroie de distribution'],
        ], 4);

        // Default view: the dismissed task is gone.
        $live = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(0, $live['member'] ?? $live['hydra:member'] ?? []);

        // "See all" view: the dismissed task reappears, carrying its dismissed status.
        $all = $client->request('GET', $vehicleIri.'/planned-tasks?includeDismissed=true', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $members = $all['member'] ?? $all['hydra:member'] ?? [];
        $this->assertCount(1, $members, 'the see-all view must surface the dismissed task');
        $this->assertSame('dismissed', $members[0]['status'] ?? null);
    }

    public function testTheCollectionKeepsDoneTasks(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'type' => 'oil_change',
            ],
        ], 3)['result']['structuredContent'];

        $vehicleIri = $plan['task']['vehicle'];

        // Logging the service closes the open task as Done → it stays on the checklist, greyed.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'vehicle' => $vehicleIri,
                'type' => 'oil_change',
                'performedAt' => '2026-06-18',
            ],
        ], 4);

        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $members = $body['member'] ?? $body['hydra:member'] ?? [];
        $this->assertCount(1, $members, 'a done task stays on the checklist with its done status');
        $this->assertSame('done', $members[0]['status'] ?? null);
    }
}
