<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PlannedTaskPatchTest extends AppTestCase
{
    use McpClientTrait;

    public function testTickingOffATaskMarksItDoneButKeepsItOnTheChecklist(): void
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
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['status' => 'done'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['status' => 'done']);

        // A done task stays on the checklist (greyed in the UI) — only its status flips.
        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $members = $body['member'] ?? $body['hydra:member'] ?? [];
        $this->assertCount(1, $members);
        $this->assertSame('done', $members[0]['status'] ?? null);
    }

    public function testDismissingATaskDropsItFromTheChecklist(): void
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
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['status' => 'dismissed'],
        ]);
        $this->assertResponseIsSuccessful();

        // Dismissed = dropped without crediting the work → off the checklist entirely.
        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(0, $body['member'] ?? $body['hydra:member'] ?? []);
    }
}
