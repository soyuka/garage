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

    public function testRefLessOilLineCarriesADerivedSpecFromTheVehicleFacts(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // An oil_change carrying the oil spec as attributes (the facts) plus a cataloged filter and a
        // bare "Huile moteur" line — the vague "×5" the spec must enrich.
        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'type' => 'oil_change',
                'parts' => [
                    ['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile'],
                    ['ref' => '', 'label' => 'Huile moteur', 'qty' => 5],
                ],
                'attributes' => [
                    ['key' => 'oil_capacity', 'value' => '4.3', 'unit' => 'L', 'source' => 'user'],
                    ['key' => 'oil_type', 'value' => 'ACEA A3/B3', 'source' => 'web'],
                    ['key' => 'oil_viscosity', 'value' => '5W-30', 'source' => 'web'],
                ],
            ],
        ], 3)['result']['structuredContent'];

        $vehicleIri = $plan['task']['vehicle'];

        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $members = $body['member'] ?? $body['hydra:member'] ?? [];
        $parts = $members[0]['parts'] ?? [];

        $byRef = [];
        foreach ($parts as $part) {
            $byRef[$part['ref'] ?? ''] = $part;
        }

        $this->assertArrayNotHasKey('spec', $byRef['W 712/73'], 'a cataloged part carries no derived fluid spec');
        $spec = $byRef['']['spec'] ?? null;
        $this->assertNotNull($spec, 'the ref-less oil line must pick up the derived spec');
        $this->assertSame('5W-30', $spec['viscosity']);
        $this->assertSame('ACEA A3/B3', $spec['standard']);
        $this->assertSame('4.3', $spec['capacity']);
        $this->assertSame('L', $spec['unit']);
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

    public function testPatchMergesPartsIntoTheShoppingListWithoutTouchingStatus(): void
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
                'type' => 'oil_filter',
                'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile']],
            ],
        ], 3)['result']['structuredContent'];

        $taskIri = $plan['task']['@id'];

        // The dashboard CTA "add reference" path: a plain PATCH that merges a part onto the task —
        // no agent turn. mergeParts dedups by ref, so the existing MANN ref survives and the BOSCH
        // ref is appended.
        $patched = $client->request('PATCH', $taskIri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['parts' => [['ref' => 'F 026 407 085', 'brand' => 'BOSCH', 'label' => 'Filtre à huile']]],
        ])->toArray();

        $refs = array_column($patched['parts'], 'ref');
        $this->assertContains('W 712/73', $refs, 'the existing ref must survive the merge');
        $this->assertContains('F 026 407 085', $refs, 'the new ref must be appended');
        $this->assertSame('open', $patched['status'], 'merging parts must not change the lifecycle');

        // A status-only PATCH must not wipe the shopping list the merge built up.
        $ticked = $client->request('PATCH', $taskIri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['status' => 'done'],
        ])->toArray();

        $this->assertSame('done', $ticked['status']);
        $this->assertCount(2, $ticked['parts'], 'ticking off must keep the parts intact');
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
                'items' => [['type' => 'oil_change', 'parts' => []]],
                'performedAt' => '2026-06-18',
            ],
        ], 4);

        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $members = $body['member'] ?? $body['hydra:member'] ?? [];
        $this->assertCount(1, $members, 'a done task stays on the checklist with its done status');
        $this->assertSame('done', $members[0]['status'] ?? null);
    }
}
