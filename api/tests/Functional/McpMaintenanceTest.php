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
        $this->assertContains('list_due_maintenance', $tools);
    }

    public function testLoggingUnderAnAmbiguousMakeSurfacesCandidateInsteadOfCreating(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // A TORO 246-H already exists in the garage.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'key' => 'oil_type',
                'value' => 'SAE 5W-30',
                'source' => 'llm',
            ],
        ], 3);

        // Logging a service under the other brand string must surface the candidate, not dup.
        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'Wheel Horse',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'type' => 'oil_change',
                'performedAt' => '2026-05-01',
            ],
        ], 4)['result']['structuredContent'];

        $this->assertSame('ambiguous_match', $log['status']);
        $this->assertSame('TORO', $log['candidates'][0]['make']);
        $this->assertMatchesRegularExpression('~^/garage/vehicles/[0-9A-HJKMNP-TV-Z]{26}$~', $log['candidates'][0]['@id']);

        $list = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 5)['result']['structuredContent'];
        $this->assertSame(1, $list['count'], 'An ambiguous log must not create a second row.');
    }

    public function testLoggingByVehicleIriAppendsToTheExistingRow(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'key' => 'oil_type',
                'value' => 'SAE 5W-30',
                'source' => 'llm',
            ],
        ], 3);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 4)['result']['structuredContent']['vehicles'][0]['@id'];

        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'vehicle' => $iri,
                'type' => 'oil_change',
                'performedAt' => '2026-05-01',
            ],
        ], 5)['result']['structuredContent'];
        $this->assertSame('ok', $log['status']);

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare'],
        ], 6)['result']['structuredContent'];
        $this->assertCount(1, $history['records'], 'Logging by @id must append to the existing row.');

        $list = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 7)['result']['structuredContent'];
        $this->assertSame(1, $list['count']);
    }

    public function testHistoryByVehicleIri(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'type' => 'oil_change',
                'performedAt' => '2026-05-01',
            ],
        ], 3);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 4)['result']['structuredContent']['vehicles'][0]['@id'];

        // History by the @id alone — no make/model.
        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['vehicle' => $iri],
        ], 5)['result']['structuredContent'];

        $this->assertSame('ok', $history['status']);
        $this->assertSame($iri, $history['vehicle']['@id']);
        $this->assertCount(1, $history['records']);
    }

    public function testMaintenanceRecordExposesDereferenceableIri(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'type' => 'oil_change',
                'performedAt' => '2026-05-01',
            ],
        ], 3);

        $records = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare'],
        ], 4)['result']['structuredContent']['records'];

        $iri = $records[0]['@id'] ?? null;
        $this->assertMatchesRegularExpression('~^/garage/maintenance-records/[0-9A-HJKMNP-TV-Z]{26}$~', (string) $iri);

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['type' => 'oil_change', 'performedAt' => '2026-05-01']);
    }

    public function testLoggingWithConfirmNewCreatesDistinctRowDespiteCandidate(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'key' => 'oil_type',
                'value' => 'SAE 5W-30',
                'source' => 'llm',
            ],
        ], 3);

        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'Wheel Horse',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'confirmNew' => true,
                'type' => 'oil_change',
                'performedAt' => '2026-05-01',
            ],
        ], 4)['result']['structuredContent'];
        $this->assertSame('ok', $log['status']);

        $list = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 5)['result']['structuredContent'];
        $this->assertSame(2, $list['count'], 'confirmNew must create the distinct row.');
    }

    public function testListDueMaintenanceFlagsAnOverdueServiceAndSurfacesGaps(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // An oil change far in the past — overdue regardless of the wall clock.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'type' => 'oil_change',
                'performedAt' => '2018-01-01',
            ],
        ], 3);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_due_maintenance',
            'arguments' => [],
        ], 4);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertGreaterThanOrEqual(1, $content['count']);

        $oil = $this->dueItem($content['due'], 'IVECO', 'oil_change');
        $this->assertTrue($oil['overdue']);
        $this->assertLessThan(0, $oil['daysUntilDue']);
        $this->assertSame('DAILY', $oil['vehicle']['model']);
        // Usage axis is advisory: the interval is surfaced so the host can ask for a live reading.
        $this->assertSame(10000, $oil['usageInterval']['value']);
        $this->assertSame('km', $oil['usageInterval']['unit']);

        // The gap half: recurring services never logged for this vehicle are surfaced, not hidden.
        $gap = $this->gapFor($content['gaps'], 'IVECO', 'DAILY');
        $this->assertContains('air_filter', $gap['unknownBaseline']);
        $this->assertNotContains('oil_change', $gap['unknownBaseline']);

        $this->assertNotEmpty($content['notes'], 'the result must spell out the time-vs-usage and missing-history caveats');
    }

    public function testListDueMaintenanceWithinHorizonExcludesFarFutureServices(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Done today-ish → next oil change ~12 months out, well beyond a 30-day horizon.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'PEUGEOT',
                'model' => '308',
                'type' => 'oil_change',
                'performedAt' => (new \DateTimeImmutable())->format('Y-m-d'),
            ],
        ], 3);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_due_maintenance',
            'arguments' => ['withinDays' => 30],
        ], 4);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame([], array_filter(
            $content['due'],
            static fn (array $d): bool => 'PEUGEOT' === $d['vehicle']['make'],
        ), 'a service due ~12 months out must not appear within a 30-day horizon');
    }

    public function testListDueMaintenanceOnEmptyGarageIsOkWithCountZero(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_due_maintenance',
            'arguments' => [],
        ], 3);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame(0, $content['count']);
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

    /**
     * @param list<array<string, mixed>> $due
     *
     * @return array<string, mixed>
     */
    private function dueItem(array $due, string $make, string $type): array
    {
        foreach ($due as $item) {
            if ($make === $item['vehicle']['make'] && $type === $item['type']) {
                return $item;
            }
        }

        $this->fail(sprintf('No due %s for %s in %s', $type, $make, json_encode($due)));
    }

    /**
     * @param list<array<string, mixed>> $gaps
     *
     * @return array<string, mixed>
     */
    private function gapFor(array $gaps, string $make, string $model): array
    {
        foreach ($gaps as $gap) {
            if ($make === $gap['vehicle']['make'] && $model === $gap['vehicle']['model']) {
                return $gap;
            }
        }

        $this->fail(sprintf('No gaps for %s %s in %s', $make, $model, json_encode($gaps)));
    }

    /**
     * Claude connector-directory submission requires every tool to carry a title plus the
     * applicable readOnlyHint/destructiveHint annotation; the hints are also a portable MCP
     * signal every host (ChatGPT Apps, Claude) uses to gate confirmation prompts.
     */
    public function testMaintenanceToolsExposeTitleAndBehaviourHints(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        $read = $tools['get_maintenance_history'];
        $this->assertNotEmpty($read['title'] ?? null, 'read tool must carry a title');
        $this->assertTrue($read['annotations']['readOnlyHint'] ?? null, 'history is read-only');

        $write = $tools['log_maintenance'];
        $this->assertNotEmpty($write['title'] ?? null, 'write tool must carry a title');
        $this->assertFalse($write['annotations']['readOnlyHint'] ?? null, 'logging modifies state');
        // Append-only log: additive, never overwrites/deletes — so destructiveHint must be false.
        $this->assertFalse($write['annotations']['destructiveHint'] ?? null, 'append-only log is not destructive');
    }

    public function testLoggingMaintenanceOnANewVehicleCapturesTheUsageReadingOnTheVehicle(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // log_maintenance creates the vehicle on first write; the reading the user mentioned
        // ("vidange à 250 000 km") must land on the vehicle's own usage, not only on the record.
        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'type' => 'oil_change',
                'performedAt' => '2026-05-01',
                'usageValue' => 250000,
                'usageUnit' => 'km',
            ],
        ], 3)['result']['structuredContent'];

        $this->assertSame('ok', $log['status']);

        $list = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => ['make' => 'IVECO'],
        ], 4)['result']['structuredContent'];
        $this->assertSame(250000, $list['vehicles'][0]['usageValue']);
        $this->assertSame('km', $list['vehicles'][0]['usageUnit']);
    }

    public function testLoggingMaintenanceWithAHigherReadingAdvancesTheVehicleUsage(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'usageValue' => 100000,
                'usageUnit' => 'km',
            ],
        ], 3)['result']['structuredContent']['vehicle']['@id'];

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'vehicle' => $iri,
                'type' => 'oil_change',
                'performedAt' => '2026-06-01',
                'usageValue' => 120000,
                'usageUnit' => 'km',
            ],
        ], 4);

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['usageValue' => 120000, 'usageUnit' => 'km']);
    }

    public function testLoggingMaintenanceWithALowerReadingDoesNotRegressTheVehicleUsage(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'usageValue' => 120000,
                'usageUnit' => 'km',
            ],
        ], 3)['result']['structuredContent']['vehicle']['@id'];

        // A backdated service logged at an earlier odometer must not rewind the current reading.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'vehicle' => $iri,
                'type' => 'oil_change',
                'performedAt' => '2025-01-01',
                'usageValue' => 90000,
                'usageUnit' => 'km',
            ],
        ], 4);

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['usageValue' => 120000, 'usageUnit' => 'km']);
    }
}
