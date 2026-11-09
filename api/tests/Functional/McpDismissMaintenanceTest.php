<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpDismissMaintenanceTest extends AppTestCase
{
    use McpClientTrait;

    public function testDismissMaintenanceToolIsDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->listTools($client, $sessionId), 'name');

        $this->assertContains('dismiss_maintenance', $tools);
    }

    public function testDismissDropsAnOpenTaskOffTheChecklistButKeepsItInDb(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $plan = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'confirmNew' => true,
                'type' => 'timing_belt',
                'label' => 'courroie de distribution',
                'targetUsageValue' => 250000,
                'targetUsageUnit' => 'km',
            ],
        ], 3));

        $vehicleIri = $plan['task']['vehicle'];
        $taskIri = $plan['task']['@id'];

        // Sanity: the task is on the live checklist before we remove it.
        $before = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(1, $before['member'] ?? $before['hydra:member'] ?? []);

        $dismiss = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'dismiss_maintenance',
            'arguments' => [
                'vehicle' => $vehicleIri,
                'type' => 'timing_belt',
                'label' => 'courroie de distribution',
            ],
        ], 4));

        $this->assertSame('ok', $dismiss['status']);
        $this->assertSame('dismissed', $dismiss['task']['status']);
        $this->assertSame($taskIri, $dismiss['task']['@id']);

        // Dropped off the live checklist…
        $after = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(0, $after['member'] ?? $after['hydra:member'] ?? []);

        // …but NOT deleted: the row still dereferences, carrying status "dismissed" (soft-delete).
        $kept = $client->request('GET', $taskIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame('dismissed', $kept['status']);
    }

    public function testDismissingAnUnknownTaskReportsTaskNotFound(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $plan = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'type' => 'oil_change',
            ],
        ], 3));

        $vehicleIri = $plan['task']['vehicle'];

        $dismiss = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'dismiss_maintenance',
            'arguments' => [
                'vehicle' => $vehicleIri,
                'type' => 'brakes',
            ],
        ], 4));

        $this->assertSame('task_not_found', $dismiss['error']);
    }

    public function testDismissWithoutVehicleIdentificationIsRejected(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $dismiss = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'dismiss_maintenance',
            'arguments' => ['type' => 'oil_change'],
        ], 3));

        $this->assertSame('invalid_input', $dismiss['error']);
    }
}
