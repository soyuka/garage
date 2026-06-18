<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpPlanMaintenanceTest extends AppTestCase
{
    use McpClientTrait;

    public function testPlanMaintenanceToolIsDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        $this->assertContains('plan_maintenance', $tools);
    }

    public function testPlanCreatesOpenTaskCarryingItsParts(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'confirmNew' => true,
                'type' => 'oil_change',
                'targetUsageValue' => 250000,
                'targetUsageUnit' => 'km',
                'parts' => [
                    ['ref' => 'W 712/75', 'label' => 'Filtre à huile', 'brand' => 'MANN'],
                    ['ref' => 'OEM-DRAIN-GASKET', 'label' => 'Joint de bouchon de vidange'],
                ],
            ],
        ], 3)['result']['structuredContent'];

        $this->assertSame('ok', $plan['status']);
        $this->assertSame('open', $plan['task']['status']);
        $this->assertSame('oil_change', $plan['task']['type']);
        $this->assertSame(250000, $plan['task']['target']['value']);
        $this->assertSame('km', $plan['task']['target']['unit']);
        $this->assertCount(2, $plan['task']['parts']);
        $this->assertSame('W 712/75', $plan['task']['parts'][0]['ref']);

        // The task is read back on the vehicle item as part of the open checklist.
        $vehicleIri = $plan['task']['vehicle'];
        $client->request('GET', $vehicleIri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['openTasks' => 1]);
    }

    public function testReRunningTheSameServiceUpsertsRatherThanDuplicating(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $first = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'type' => 'oil_change',
                'parts' => [['ref' => 'OLD-REF']],
            ],
        ], 3)['result']['structuredContent'];

        $vehicleIri = $first['task']['vehicle'];

        // Same vehicle + same type again, addressed by @id — must update the existing open task.
        $second = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'vehicle' => $vehicleIri,
                'type' => 'oil_change',
                'parts' => [['ref' => 'NEW-REF']],
            ],
        ], 4)['result']['structuredContent'];

        $this->assertSame('ok', $second['status']);
        $this->assertSame($first['task']['@id'], $second['task']['@id'], 'A re-run of the same service must update the same task, not create a new one.');
        $this->assertSame('NEW-REF', $second['task']['parts'][0]['ref']);

        $client->request('GET', $vehicleIri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertJsonContains(['openTasks' => 1]);
    }
}
