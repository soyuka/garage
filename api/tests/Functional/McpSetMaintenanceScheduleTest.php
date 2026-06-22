<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The set_maintenance_schedule tool: the write path that lets the agent persist a full multi-cadence
 * manufacturer schedule (the TORO 246-H answer) as recurring rules, instead of collapsing it to one
 * log event — and then drives the usage-aware forecast off those rules.
 */
final class McpSetMaintenanceScheduleTest extends AppTestCase
{
    use McpClientTrait;

    public function testToolIsDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        $this->assertContains('set_maintenance_schedule', $tools);
    }

    public function testPersistsEveryCadenceAsItsOwnRuleNeverCollapsed(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'set_maintenance_schedule',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'confirmNew' => true,
                'items' => [
                    ['type' => 'oil_change', 'action' => 'change', 'everyUsageValue' => 50, 'everyUsageUnit' => 'hours', 'firstUsageValue' => 5],
                    ['type' => 'oil_filter', 'action' => 'replace', 'everyUsageValue' => 200, 'everyUsageUnit' => 'hours'],
                    ['type' => 'air_filter', 'action' => 'clean', 'component' => 'foam element', 'everyUsageValue' => 25, 'everyUsageUnit' => 'hours', 'everyMonths' => 12],
                    ['type' => 'air_filter', 'action' => 'replace', 'component' => 'paper cartridge', 'everyUsageValue' => 100, 'everyUsageUnit' => 'hours', 'everyMonths' => 12],
                    ['type' => 'spark_plug', 'action' => 'inspect', 'everyUsageValue' => 25, 'everyUsageUnit' => 'hours'],
                    ['type' => 'spark_plug', 'action' => 'replace', 'everyUsageValue' => 100, 'everyUsageUnit' => 'hours'],
                ],
            ],
        ], 3)['result']['structuredContent'];

        $this->assertSame('ok', $result['status']);
        $this->assertSame(6, $result['count'], 'Six independent cadences — two of them sharing the type air_filter / spark_plug.');

        // The two air_filter cadences coexist (foam clean vs paper replace) — not merged.
        $airFilter = array_values(array_filter($result['schedule'], static fn (array $r): bool => 'air_filter' === $r['type']));
        $this->assertCount(2, $airFilter);
        $byAction = array_column($airFilter, null, 'action');
        $this->assertSame('foam element', $byAction['clean']['component']);
        $this->assertSame(25, $byAction['clean']['everyUsage']['value']);
        $this->assertSame('hours', $byAction['clean']['everyUsage']['unit']);
        $this->assertSame(100, $byAction['replace']['everyUsage']['value']);

        $oil = array_values(array_filter($result['schedule'], static fn (array $r): bool => 'oil_change' === $r['type']))[0];
        $this->assertSame(5, $oil['firstUsageValue'], 'Break-in override preserved.');
        $this->assertFalse($oil['verified'], 'A web-sourced schedule is advisory, not authoritative.');
    }

    public function testReSendingACadenceUpsertsInPlaceNoDuplicate(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $args = [
            'make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare', 'confirmNew' => true,
            'items' => [['type' => 'oil_change', 'action' => 'change', 'everyUsageValue' => 50, 'everyUsageUnit' => 'hours']],
        ];
        $this->call($client, $sessionId, 'tools/call', ['name' => 'set_maintenance_schedule', 'arguments' => $args], 3);

        // Re-send the same (type, action) with a corrected cadence → replaces in place.
        $args['items'][0]['everyUsageValue'] = 60;
        $result = $this->call($client, $sessionId, 'tools/call', ['name' => 'set_maintenance_schedule', 'arguments' => $args], 4)['result']['structuredContent'];

        $this->assertSame(1, $result['count'], 'oil_change:change is one rule — refreshed, not duplicated.');
        $this->assertSame(60, $result['schedule'][0]['everyUsage']['value']);
    }

    public function testScheduleDrivesUsageAwareForecast(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Schedule: oil every 50 engine-hours.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'set_maintenance_schedule',
            'arguments' => [
                'make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare', 'confirmNew' => true,
                'items' => [
                    ['type' => 'oil_change', 'action' => 'change', 'everyUsageValue' => 50, 'everyUsageUnit' => 'hours'],
                    ['type' => 'air_filter', 'action' => 'replace', 'everyUsageValue' => 100, 'everyUsageUnit' => 'hours'],
                ],
            ],
        ], 3);

        // One oil change logged at 150 h — the baseline + the current reading.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare',
                'items' => [['type' => 'oil_change', 'parts' => []]],
                'performedAt' => '2026-05-01',
                'usageValue' => 150,
                'usageUnit' => 'hours',
            ],
        ], 4);

        $content = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_due_maintenance',
            'arguments' => ['vehicleType' => 'groundscare'],
        ], 5)['result']['structuredContent'];

        $oil = $this->dueItem($content['due'], 'TORO', 'oil_change');
        $this->assertSame('schedule', $oil['rule'], 'The per-vehicle rule drove this, not a generic default.');
        $this->assertSame('change', $oil['action']);
        $this->assertSame(200, $oil['usage']['due'], 'baseline 150 h + 50 h cadence.');
        $this->assertSame(50, $oil['usage']['remaining'], 'due 200 − current 150.');
        $this->assertSame('hours', $oil['usage']['unit']);

        // air_filter has a known cadence but no logged baseline → an honest gap, not a false forecast.
        $gap = $this->gapFor($content['gaps'], 'TORO', '246-H');
        $this->assertContains('air_filter', $gap['unknownBaseline']);
    }

    /**
     * @param list<array<string, mixed>> $due
     *
     * @return array<string, mixed>
     */
    private function dueItem(array $due, string $make, string $type): array
    {
        foreach ($due as $item) {
            if ($item['vehicle']['make'] === $make && $item['type'] === $type) {
                return $item;
            }
        }

        self::fail(sprintf('No due item for %s %s.', $make, $type));
    }

    /**
     * @param list<array<string, mixed>> $gaps
     *
     * @return array<string, mixed>
     */
    private function gapFor(array $gaps, string $make, string $model): array
    {
        foreach ($gaps as $gap) {
            if ($gap['vehicle']['make'] === $make && $gap['vehicle']['model'] === $model) {
                return $gap;
            }
        }

        self::fail(sprintf('No gaps entry for %s %s.', $make, $model));
    }
}
