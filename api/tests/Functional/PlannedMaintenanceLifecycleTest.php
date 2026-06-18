<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PlannedMaintenanceLifecycleTest extends AppTestCase
{
    use McpClientTrait;

    public function testLoggingAServiceClosesTheMatchingOpenPlan(): void
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

        $client->request('GET', $vehicleIri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertJsonContains(['openTasks' => 1]);

        // Logging the same service this plan was about must auto-close the open task.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'vehicle' => $vehicleIri,
                'type' => 'oil_change',
                'performedAt' => '2026-06-18',
            ],
        ], 4);

        $client->request('GET', $vehicleIri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertJsonContains(['openTasks' => 0]);
    }
}
