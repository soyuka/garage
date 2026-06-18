<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PlannedTaskPatchTest extends AppTestCase
{
    use McpClientTrait;

    public function testTickingOffATaskMarksItDoneAndDropsItFromTheChecklist(): void
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
                'type' => 'brakes',
            ],
        ], 3)['result']['structuredContent'];

        $taskIri = $plan['task']['@id'];
        $vehicleIri = $plan['task']['vehicle'];

        $client->request('PATCH', $taskIri, [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['status' => 'done'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['status' => 'done']);

        // A done task leaves the open checklist.
        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(0, $body['member'] ?? $body['hydra:member'] ?? []);
    }
}
