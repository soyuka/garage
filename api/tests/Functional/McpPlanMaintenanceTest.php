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

        $plan = $this->outcome($this->call($client, $sessionId, 'tools/call', [
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
        ], 3));

        $this->assertSame('ok', $plan['status']);
        $this->assertSame('open', $plan['task']['status']);
        $this->assertSame('oil_change', $plan['task']['type']);
        $this->assertSame(250000, $plan['task']['target']['value']);
        $this->assertSame('km', $plan['task']['target']['unit']);
        $this->assertCount(2, $plan['task']['parts']);
        $this->assertSame('W 712/75', $plan['task']['parts'][0]['ref']);

        // The task is read back on the vehicle's planned-tasks subresource (the open checklist).
        $vehicleIri = $plan['task']['vehicle'];
        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $body['member'] ?? $body['hydra:member'] ?? []);
    }

    public function testPlanCarriesPartPriceAndSupplierAndReplanKeepsThemWhenOmitted(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        $base = ['make' => 'MAZDA', 'model' => 'MAZDA6', 'confirmNew' => true, 'type' => 'oil_filter'];

        $first = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => $base + ['parts' => [['ref' => 'W 712/75', 'brand' => 'MANN', 'price' => 12.5, 'supplier' => 'trodo.fr']]],
        ], 3));

        $this->assertSame(1250, $first['task']['parts'][0]['priceCents']);
        $this->assertSame('trodo.fr', $first['task']['parts'][0]['supplier']);

        $omitted = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => $base + ['parts' => [['ref' => 'W 712/75', 'qty' => 2]]],
        ], 4));

        $this->assertCount(1, $omitted['task']['parts']);
        $this->assertSame(1250, $omitted['task']['parts'][0]['priceCents'], 'a re-plan omitting price must not wipe it');
        $this->assertSame('trodo.fr', $omitted['task']['parts'][0]['supplier'], 'a re-plan omitting supplier must not wipe it');

        $updated = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => $base + ['parts' => [['ref' => 'W 712/75', 'price' => 9.99, 'supplier' => 'oscaro.com']]],
        ], 5));

        $this->assertSame(999, $updated['task']['parts'][0]['priceCents']);
        $this->assertSame('oscaro.com', $updated['task']['parts'][0]['supplier']);
    }

    public function testPlanMaintenancePartsSchemaAdvertisesPriceAndSupplier(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = $this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'];
        $tool = current(array_filter($tools, static fn (array $t): bool => 'plan_maintenance' === $t['name']));
        $props = $tool['inputSchema']['properties']['parts']['items']['properties'];

        $this->assertSame('number', $props['price']['type']);
        $this->assertSame('string', $props['supplier']['type']);
    }

    public function testPlanAcceptsAFreeFormTypeNotInTheKnownSet(): void
    {
        // The agent should label a service for what it is (timing belt, gearbox oil…) instead of
        // collapsing it to "other". The type is a free string, classified later; only forecasting
        // and icons key off the known MaintenanceType values.
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $plan = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'confirmNew' => true,
                'type' => 'timing_belt',
                'targetUsageValue' => 250000,
                'targetUsageUnit' => 'km',
            ],
        ], 3));

        $this->assertSame('ok', $plan['status']);
        $this->assertSame('timing_belt', $plan['task']['type']);
    }

    public function testReRunningTheSameServiceUpsertsRatherThanDuplicating(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $first = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'type' => 'oil_change',
                'parts' => [['ref' => 'OLD-REF']],
            ],
        ], 3));

        $vehicleIri = $first['task']['vehicle'];

        // Same vehicle + same type again, addressed by @id — must update the existing open task.
        $second = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'vehicle' => $vehicleIri,
                'type' => 'oil_change',
                'parts' => [['ref' => 'NEW-REF']],
            ],
        ], 4));

        $this->assertSame('ok', $second['status']);
        $this->assertSame($first['task']['@id'], $second['task']['@id'], 'A re-run of the same service must update the same task, not create a new one.');
        // Parts merge by ref: a partial re-plan adds to the shopping list, it does not clobber the
        // parts a prior pass resolved (the oil filter must survive when only the oil is re-sent).
        $refs = array_column($second['task']['parts'], 'ref');
        $this->assertContains('OLD-REF', $refs);
        $this->assertContains('NEW-REF', $refs);

        // The upsert leaves exactly one open task on the checklist, not two.
        $body = $client->request('GET', $vehicleIri.'/planned-tasks', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $this->assertCount(1, $body['member'] ?? $body['hydra:member'] ?? []);
    }

    public function testRefLessPartsWithDistinctLabelsAreKeptAndReplanUpdatesByLabel(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $first = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'type' => 'brake_service',
                'parts' => [
                    ['ref' => '', 'label' => 'Huile boîte 75W-80'],
                    ['ref' => '', 'label' => 'Liquide de frein DOT 4'],
                    ['ref' => '', 'label' => 'Loctite Frenbloc'],
                ],
            ],
        ], 3));

        $this->assertCount(3, $first['task']['parts']);

        $second = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'vehicle' => $first['task']['vehicle'],
                'type' => 'brake_service',
                'parts' => [['ref' => '', 'label' => 'liquide de frein dot 4', 'qty' => 2]],
            ],
        ], 4));

        $this->assertCount(3, $second['task']['parts']);
        $this->assertSame(
            ['Huile boîte 75W-80', 'liquide de frein dot 4', 'Loctite Frenbloc'],
            array_column($second['task']['parts'], 'label'),
            'a ref-less re-plan must update its own line, not the first ref-less one',
        );
        $this->assertSame([null, 2, null], array_map(static fn (array $part): ?int => $part['qty'] ?? null, $second['task']['parts']));
    }

    public function testConsumableRefIsStoredCanonicalAndReplanWithAnotherSpellingUpdatesSameLine(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        $base = ['make' => 'MAZDA', 'model' => 'MAZDA6', 'confirmNew' => true, 'type' => 'brakes'];

        $first = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => $base + ['parts' => [['ref' => 'dot4', 'label' => 'Liquide de frein Motul', 'qty' => 1], ['ref' => '623 3150 33']]],
        ], 3));

        $this->assertSame('DOT 4', $first['task']['parts'][0]['ref']);
        $this->assertSame('623 3150 33', $first['task']['parts'][1]['ref']);
        $lineId = $first['task']['parts'][0]['id'];

        $second = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => $base + ['parts' => [['ref' => 'DOT-4', 'qty' => 2]]],
        ], 4));

        $this->assertCount(2, $second['task']['parts']);
        $this->assertSame($lineId, $second['task']['parts'][0]['id']);
        $this->assertSame('DOT 4', $second['task']['parts'][0]['ref']);
        $this->assertSame(2, $second['task']['parts'][0]['qty']);
    }

    public function testPlanAndLogPartsSchemasAdvertiseTheConsumableStandards(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        $plan = $tools['plan_maintenance']['inputSchema']['properties']['parts']['description'];
        $this->assertStringContainsString('DOT 4', $plan);
        $this->assertStringContainsString('SAE 5W-30', $plan);

        $log = $tools['log_maintenance']['inputSchema']['properties']['items']['items']['properties']['parts']['description'] ?? '';
        $this->assertStringContainsString('DOT 4', $log);
    }
}
