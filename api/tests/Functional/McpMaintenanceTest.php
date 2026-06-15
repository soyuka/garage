<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpMaintenanceTest extends AppTestCase
{
    use McpClientTrait;

    public function testMaintenanceToolsAreDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        $this->assertContains('log_maintenance', $tools);
        $this->assertContains('get_maintenance_history', $tools);
    }

    public function testLogMaintenanceThenHistoryReturnsTheRecord(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'type' => 'oil_change',
                'performedAt' => '2026-06-15',
                'usageValue' => 124000,
                'usageUnit' => 'km',
                'cost' => '89.90',
                'currency' => 'EUR',
                'performedBy' => 'self',
                'partsUsed' => [['ref' => '21548991', 'label' => 'oil filter']],
            ],
        ], 3);
        $this->assertSame('ok', $log['result']['structuredContent']['status']);

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'iveco', 'model' => 'daily', 'vehicleType' => 'cv'],
        ], 4);

        $content = $history['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertCount(1, $content['records']);

        $record = $content['records'][0];
        $this->assertSame('oil_change', $record['type']);
        $this->assertStringStartsWith('2026-06-15', $record['performedAt']);
        $this->assertSame(124000, $record['usage']['value']);
        $this->assertSame('km', $record['usage']['unit']);
        $this->assertSame('89.90', $record['cost']['amount']);
        $this->assertSame('EUR', $record['cost']['currency']);
        $this->assertSame('self', $record['performedBy']);
        $this->assertSame('21548991', $record['partsUsed'][0]['ref']);
        $this->assertSame('user', $record['source']);
    }

    public function testRepeatedSameTypeAppendsRatherThanOverwrites(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        foreach (['2025-06-01', '2026-06-15'] as $i => $date) {
            $log = $this->call($client, $sessionId, 'tools/call', [
                'name' => 'log_maintenance',
                'arguments' => [
                    'make' => 'PEUGEOT',
                    'model' => '208',
                    'type' => 'oil_change',
                    'performedAt' => $date,
                ],
            ], 10 + $i);
            $this->assertSame('ok', $log['result']['structuredContent']['status']);
        }

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'PEUGEOT', 'model' => '208'],
        ], 20);

        $records = $history['result']['structuredContent']['records'];
        $this->assertCount(2, $records, 'both oil changes must persist — append-only history');
        // Newest first.
        $this->assertStringStartsWith('2026-06-15', $records[0]['performedAt']);
        $this->assertStringStartsWith('2025-06-01', $records[1]['performedAt']);
    }

    public function testHistoryFilteredByType(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        foreach ([['oil_change', '2026-01-10'], ['tires', '2026-02-20']] as $i => [$type, $date]) {
            $this->call($client, $sessionId, 'tools/call', [
                'name' => 'log_maintenance',
                'arguments' => ['make' => 'RENAULT', 'model' => 'CLIO', 'type' => $type, 'performedAt' => $date],
            ], 30 + $i);
        }

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'RENAULT', 'model' => 'CLIO', 'type' => 'tires'],
        ], 40);

        $records = $history['result']['structuredContent']['records'];
        $this->assertCount(1, $records);
        $this->assertSame('tires', $records[0]['type']);
    }

    public function testGroundscareHourMeterRoundTrips(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'type' => 'oil_change',
                'performedAt' => '2026-06-15',
                'usageValue' => 50,
                'usageUnit' => 'hours',
            ],
        ], 3);
        $this->assertSame('ok', $log['result']['structuredContent']['status']);

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare'],
        ], 4);

        $record = $history['result']['structuredContent']['records'][0];
        $this->assertSame(50, $record['usage']['value']);
        $this->assertSame('hours', $record['usage']['unit']);
    }

    public function testFreeLabelOnOtherType(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'type' => 'other',
                'label' => 'blade sharpening',
                'performedAt' => '2026-05-01',
            ],
        ], 3);

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare'],
        ], 4);

        $record = $history['result']['structuredContent']['records'][0];
        $this->assertSame('other', $record['type']);
        $this->assertSame('blade sharpening', $record['label']);
    }

    public function testHistoryOfUnknownVehicleReturnsNoRecords(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'NOSUCH', 'model' => 'GHOST', 'vehicleType' => 'car'],
        ], 3);

        $content = $history['result']['structuredContent'];
        $this->assertSame('no_records', $content['status']);
        $this->assertFalse($history['result']['isError'] ?? false);
        $this->assertSame('NOSUCH', $content['vehicle']['make']);
    }
}
