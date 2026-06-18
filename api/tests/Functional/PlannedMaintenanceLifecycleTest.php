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

        // The open checklist is the vehicle's planned-tasks subresource — the dashboard reads it to
        // render the "Tâches & liste de courses" card. The plan opens exactly one task.
        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $open = $body['member'] ?? $body['hydra:member'] ?? [];
        $this->assertCount(1, $open, 'the plan opens one task on the checklist');
        $this->assertSame('oil_change', $open[0]['type'] ?? null);

        // Logging the same service this plan was about must auto-close the open task.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'vehicle' => $vehicleIri,
                'type' => 'oil_change',
                'performedAt' => '2026-06-18',
            ],
        ], 4);

        // Closing flips the task to Done; it stays on the checklist (greyed in the UI), no longer open.
        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $members = $body['member'] ?? $body['hydra:member'] ?? [];
        $this->assertCount(1, $members, 'the matching task stays on the checklist, now done');
        $this->assertSame('done', $members[0]['status'] ?? null, 'logging the service closes the matching open plan');
    }
}
