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

    public function testTheCollectionReturnsOnlyOpenTasks(): void
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

        // Logging the service closes the only open task → the subresource empties.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'vehicle' => $vehicleIri,
                'type' => 'oil_change',
                'performedAt' => '2026-06-18',
            ],
        ], 4);

        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(0, $body['member'] ?? $body['hydra:member'] ?? [], 'closed tasks drop off the open checklist');
    }
}
