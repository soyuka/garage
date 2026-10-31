<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Uid\Ulid;

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

    public function testLoggingWithoutItemsNamesTheItemsFieldSoTheModelCanCorrect(): void
    {
        // A weaker model (e.g. Qwen) guesses a "natural" field name like `partsUsed` instead of the
        // tool's `items`, so the array binds empty and the call is rejected. The error must name the
        // exact field and show its shape, otherwise the model retries the same wrong key in a loop.
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'performedAt' => '2026-05-01',
            ],
        ], 3)['result']['structuredContent'];

        $this->assertSame('invalid_input', $log['status']);
        $this->assertStringContainsString('items', $log['reason'], 'the error must name the `items` field');
        $this->assertStringContainsString('"type"', $log['reason'], 'the error must show the item shape');
    }

    public function testLoggingUnderAnAmbiguousMakeSurfacesCandidateInsteadOfCreating(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // A TORO 246-H already exists in the garage.
        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 5W-30',
            'source' => 'llm',
        ], 3);

        // Logging a service under the other brand string must surface the candidate, not dup.
        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'Wheel Horse',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'items' => [['type' => 'oil_change', 'parts' => []]],
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

        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 5W-30',
            'source' => 'llm',
        ], 3);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 4)['result']['structuredContent']['vehicles'][0]['@id'];

        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'vehicle' => $iri,
                'items' => [['type' => 'oil_change', 'parts' => []]],
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
                'items' => [['type' => 'oil_change', 'parts' => []]],
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

    public function testKnownServiceLabelsAreCanonicalisedWhileTheLongTailIsKept(): void
    {
        // The "Huile" bug: a weaker model logs a KNOWN service under its French label instead of the
        // canonical snake_case key, so it never matches the schedule/forecast. The recorder must fold
        // recognised aliases to canonical — and leave an unplanned service (fuel_line) untouched.
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'items' => [
                    ['type' => 'Huile', 'parts' => [['ref' => 'SAE30-2L']]],
                    ['type' => 'Bougies', 'parts' => []],
                    ['type' => 'fuel_line', 'parts' => []],
                ],
                'performedAt' => '2026-05-01',
            ],
        ], 3);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 4)['result']['structuredContent']['vehicles'][0]['@id'];

        $record = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['vehicle' => $iri],
        ], 5)['result']['structuredContent']['records'][0];

        $this->assertSame(['oil_change', 'spark_plug', 'fuel_line'], array_column($record['items'], 'type'));
        // The canonicalisation never disturbs the parts riding on the item.
        $this->assertSame('SAE30-2L', $record['items'][0]['parts'][0]['ref']);
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
                'items' => [['type' => 'oil_change', 'parts' => []]],
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
        $this->assertJsonContains(['items' => [['type' => 'oil_change', 'parts' => []]], 'performedAt' => '2026-05-01']);
    }

    public function testLoggingWithConfirmNewCreatesDistinctRowDespiteCandidate(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 5W-30',
            'source' => 'llm',
        ], 3);

        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'Wheel Horse',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'confirmNew' => true,
                'items' => [['type' => 'oil_change', 'parts' => []]],
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

    public function testEditMaintenanceUpdatesAVisitInPlaceWithoutDuplicating(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // A logged visit carrying a cost, notes and a performedBy.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'items' => [['type' => 'oil_change', 'parts' => []]],
                'performedAt' => '2026-06-15',
                'notes' => 'first pass',
                'performedBy' => 'self',
            ],
        ], 3);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv'],
        ], 4)['result']['structuredContent']['records'][0]['@id'];

        // Edit only the cost and notes; performedBy is NOT supplied — a sparse edit must leave it.
        $edit = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'edit_maintenance',
            'arguments' => [
                'record' => $iri,
                'cost' => '149.90',
                'currency' => 'EUR',
                'notes' => 'corrected bill',
            ],
        ], 5)['result']['structuredContent'];
        $this->assertSame('ok', $edit['status']);

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv'],
        ], 6)['result']['structuredContent'];

        $this->assertCount(1, $history['records'], 'an edit must mutate the visit in place, never append a second row');
        $record = $history['records'][0];
        $this->assertSame($iri, $record['@id'], 'the edited record keeps its identity (same @id)');
        $this->assertSame('149.90', $record['cost']['amount']);
        $this->assertSame('corrected bill', $record['notes']);
        $this->assertSame('self', $record['performedBy'], 'a field omitted from the edit is left untouched');
        $this->assertSame('oil_change', $record['items'][0]['type'], 'items untouched when not supplied');
    }

    public function testEditMaintenanceOnAnUnknownRecordIsNotFoundNotAnError(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $edit = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'edit_maintenance',
            'arguments' => [
                'record' => '/garage/maintenance-records/'.new Ulid(),
                'notes' => 'ghost',
            ],
        ], 3);

        $this->assertFalse($edit['result']['isError'] ?? false, 'a missing record is a result, not a protocol error');
        $this->assertSame('not_found', $edit['result']['structuredContent']['status']);
    }

    public function testEditMaintenanceReplacesItemsWhenSupplied(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'items' => [['type' => 'oil_change', 'parts' => []]],
                'performedAt' => '2026-06-15',
            ],
        ], 3);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv'],
        ], 4)['result']['structuredContent']['records'][0]['@id'];

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'edit_maintenance',
            'arguments' => [
                'record' => $iri,
                'items' => [
                    ['type' => 'oil_change', 'parts' => [['ref' => 'W712/73', 'brand' => 'MANN-FILTER']]],
                    ['type' => 'oil_filter', 'parts' => []],
                ],
            ],
        ], 5);

        $record = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv'],
        ], 6)['result']['structuredContent']['records'][0];

        $this->assertSame(['oil_change', 'oil_filter'], array_column($record['items'], 'type'));
        $this->assertSame('W712/73', $record['items'][0]['parts'][0]['ref']);
    }

    public function testLoggingAndEditingPartPriceStoresCents(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // A part price entered in euros is stored as integer cents — the same chokepoint the dashboard
        // uses, so the agent and the UI agree on the shape.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'items' => [['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'brand' => 'Motul', 'price' => 12.5]]]],
                'performedAt' => '2026-06-15',
            ],
        ], 3);

        $record = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv'],
        ], 4)['result']['structuredContent']['records'][0];
        $this->assertSame(1250, $record['items'][0]['parts'][0]['priceCents']);
        $this->assertArrayNotHasKey('price', $record['items'][0]['parts'][0], 'the wire "price" is converted, never stored raw');

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'edit_maintenance',
            'arguments' => [
                'record' => $record['@id'],
                'items' => [['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'brand' => 'Motul', 'price' => 14.9]]]],
            ],
        ], 5);

        $edited = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv'],
        ], 6)['result']['structuredContent']['records'][0];
        $this->assertSame(1490, $edited['items'][0]['parts'][0]['priceCents']);
    }

    public function testEditMaintenanceToolIsDiscoverableAndCarriesIdempotentHint(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        $this->assertArrayHasKey('edit_maintenance', $tools);
        $edit = $tools['edit_maintenance'];
        $this->assertNotEmpty($edit['title'] ?? null);
        $this->assertFalse($edit['annotations']['readOnlyHint'] ?? null, 'editing modifies state');
        // Re-sending the same edit yields the same record — idempotent, and not destructive (in-place).
        $this->assertTrue($edit['annotations']['idempotentHint'] ?? null);
        $this->assertFalse($edit['annotations']['destructiveHint'] ?? null);
    }

    public function testLoggedPartsSurfaceAsVehicleFacts(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Logging a visit that fitted real parts must make those parts queryable as facts — no
        // separate fact-entry step. The part is the single store; the fact is a projection of it.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'items' => [
                    ['type' => 'oil_filter', 'parts' => [['ref' => 'W712/73', 'brand' => 'MANN-FILTER']]],
                    ['type' => 'air_filter', 'parts' => [['ref' => 'C25024']]],
                ],
                'performedAt' => '2026-06-15',
            ],
        ], 3);

        $facts = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => ['make' => 'MAZDA', 'model' => 'MAZDA6'],
        ], 4)['result']['structuredContent']['facts'];

        $byKey = array_column($facts, null, 'key');
        $this->assertArrayHasKey('oil_filter_ref', $byKey);
        $this->assertSame('MANN-FILTER W712/73', $byKey['oil_filter_ref']['value']);
        $this->assertSame('log', $byKey['oil_filter_ref']['source']);
        $this->assertArrayHasKey('air_filter_ref', $byKey);
        $this->assertSame('C25024', $byKey['air_filter_ref']['value']);
    }

    public function testListDueMaintenanceFlagsAnOverdueServiceAndSurfacesGaps(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // The forecast is schedule-only: define the vehicle's cadences first (oil yearly, air filter
        // every 2 years), then a logged service anchors the prediction.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'set_maintenance_schedule',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'items' => [
                    ['type' => 'oil_change', 'action' => 'change', 'everyMonths' => 12],
                    ['type' => 'air_filter', 'action' => 'replace', 'everyMonths' => 24],
                ],
            ],
        ], 3);

        // An oil change far in the past — overdue against the 12-month cadence regardless of the clock.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'items' => [['type' => 'oil_change', 'parts' => []]],
                'performedAt' => '2018-01-01',
            ],
        ], 4);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_due_maintenance',
            'arguments' => [],
        ], 5);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertGreaterThanOrEqual(1, $content['count']);

        $oil = $this->dueItem($content['due'], 'IVECO', 'oil_change');
        $this->assertTrue($oil['overdue']);
        $this->assertLessThan(0, $oil['daysUntilDue']);
        $this->assertSame('DAILY', $oil['vehicle']['model']);
        $this->assertSame('schedule', $oil['rule'], 'every forecast is schedule-driven now');

        // The gap half: a SCHEDULED service never logged for this vehicle is surfaced, not hidden.
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
                'items' => [['type' => 'oil_change', 'parts' => []]],
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
                'items' => [['type' => 'oil_change', 'parts' => [['ref' => '21548991', 'label' => 'oil filter']]]],
                'performedAt' => '2026-06-15',
                'usageValue' => 124000,
                'usageUnit' => 'km',
                'cost' => '89.90',
                'currency' => 'EUR',
                'performedBy' => 'self',
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
        $this->assertSame('oil_change', $record['items'][0]['type']);
        $this->assertStringStartsWith('2026-06-15', $record['performedAt']);
        $this->assertSame(124000, $record['usage']['value']);
        $this->assertSame('km', $record['usage']['unit']);
        $this->assertSame('89.90', $record['cost']['amount']);
        $this->assertSame('EUR', $record['cost']['currency']);
        $this->assertSame('self', $record['performedBy']);
        $this->assertSame('21548991', $record['items'][0]['parts'][0]['ref']);
        $this->assertSame('user', $record['source']);
    }

    public function testOneVisitLogsMultipleItemsWithACostAsASingleRecord(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // A vidange that changed oil + oil filter + fuel filter on one date is ONE visit carrying
        // three items and a single bill — not three separate records.
        $log = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'performedAt' => '2025-01-10',
                'performedBy' => 'self',
                'cost' => '189.00',
                'currency' => 'EUR',
                'items' => [
                    ['type' => 'oil_change', 'parts' => [['ref' => 'GF5W30', 'brand' => 'TOTAL', 'label' => 'Quartz 5W30']]],
                    ['type' => 'oil_filter', 'parts' => [['ref' => 'W712/73', 'brand' => 'MANN-FILTER']]],
                    ['type' => 'fuel_filter', 'parts' => []],
                ],
            ],
        ], 3)['result']['structuredContent'];
        $this->assertSame('ok', $log['status']);

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'MAZDA', 'model' => 'MAZDA6'],
        ], 4)['result']['structuredContent'];

        $this->assertCount(1, $history['records'], 'the visit is a single record, not one per part');
        $visit = $history['records'][0];
        $this->assertSame(['oil_change', 'oil_filter', 'fuel_filter'], array_column($visit['items'], 'type'));
        $this->assertSame('189.00', $visit['cost']['amount']);
        $this->assertSame('W712/73', $visit['items'][1]['parts'][0]['ref']);

        // Each item seeds its own forecast — a type-narrowed history finds the visit.
        $byFilter = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'MAZDA', 'model' => 'MAZDA6', 'type' => 'fuel_filter'],
        ], 5)['result']['structuredContent'];
        $this->assertCount(1, $byFilter['records'], 'a per-type history query matches a visit that contains that item type');
    }

    public function testHistoryReportsEstimatedCostFromPricedParts(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // No cost/currency passed — only priced parts. The agent path must fall back to the same
        // parts-sum estimate the dashboard already shows via effectiveCostCents/costSource.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'SUBARU',
                'model' => 'FORESTER',
                'performedAt' => '2026-03-01',
                'items' => [
                    ['type' => 'oil_change', 'parts' => [['ref' => '5W30', 'brand' => 'Motul', 'price' => 25.90]]],
                    ['type' => 'oil_filter', 'parts' => [['ref' => 'W712/73', 'brand' => 'MANN-FILTER', 'price' => 7.50]]],
                ],
            ],
        ], 3);

        $estimated = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'SUBARU', 'model' => 'FORESTER'],
        ], 4)['result']['structuredContent']['records'][0];

        $this->assertSame(['amount' => '33.40', 'currency' => null, 'source' => 'estimated'], $estimated['cost']);

        // A visit with an explicit bill still reports 'bill' — the source key must not regress the
        // already-working path.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'log_maintenance',
            'arguments' => [
                'make' => 'SUBARU',
                'model' => 'FORESTER',
                'performedAt' => '2026-03-15',
                'cost' => '50.00',
                'currency' => 'EUR',
                'items' => [['type' => 'tires', 'parts' => []]],
            ],
        ], 5);

        $billed = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'SUBARU', 'model' => 'FORESTER', 'type' => 'tires'],
        ], 6)['result']['structuredContent']['records'][0];

        $this->assertSame(['amount' => '50.00', 'currency' => 'EUR', 'source' => 'bill'], $billed['cost']);
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
                    'items' => [['type' => 'oil_change', 'parts' => []]],
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
                'arguments' => ['make' => 'RENAULT', 'model' => 'CLIO', 'items' => [['type' => $type, 'parts' => []]], 'performedAt' => $date],
            ], 30 + $i);
        }

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'RENAULT', 'model' => 'CLIO', 'type' => 'tires'],
        ], 40);

        $records = $history['result']['structuredContent']['records'];
        $this->assertCount(1, $records);
        $this->assertSame('tires', $records[0]['items'][0]['type']);
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
                'items' => [['type' => 'oil_change', 'parts' => []]],
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
                'items' => [['type' => 'other', 'label' => 'blade sharpening', 'parts' => []]],
                'performedAt' => '2026-05-01',
            ],
        ], 3);

        $history = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_maintenance_history',
            'arguments' => ['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare'],
        ], 4);

        $record = $history['result']['structuredContent']['records'][0];
        $this->assertSame('other', $record['items'][0]['type']);
        $this->assertSame('blade sharpening', $record['items'][0]['label']);
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

        $this->fail(\sprintf('No due %s for %s in %s', $type, $make, json_encode($due)));
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

        $this->fail(\sprintf('No gaps for %s %s in %s', $make, $model, json_encode($gaps)));
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
                'items' => [['type' => 'oil_change', 'parts' => []]],
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
                'items' => [['type' => 'oil_change', 'parts' => []]],
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
                'items' => [['type' => 'oil_change', 'parts' => []]],
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
