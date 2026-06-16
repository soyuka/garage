<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpGarageTest extends AppTestCase
{
    use McpClientTrait;

    public function testGarageToolsAreDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        $this->assertContains('get_vehicle_facts', $tools);
        $this->assertContains('add_vehicle_fact', $tools);
        $this->assertContains('list_vehicles', $tools);
    }

    public function testListVehiclesReturnsStoredVehiclesWithFactCount(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Seed two distinct vehicles in the shared garage.
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
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'key' => 'oil_type',
                'value' => 'SAE 5W-40',
                'source' => 'llm',
            ],
        ], 4);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 5);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame(2, $content['count']);

        $makes = array_column($content['vehicles'], 'make');
        $this->assertContains('TORO', $makes);
        $this->assertContains('IVECO', $makes);

        $toro = $this->vehicleByMake($content['vehicles'], 'TORO');
        $this->assertSame('246-H', $toro['model']);
        $this->assertSame('groundscare', $toro['vehicleType']);
        $this->assertSame(1, $toro['factCount']);
    }

    public function testListVehiclesFiltersByVehicleType(): void
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
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'key' => 'oil_type',
                'value' => 'SAE 5W-40',
                'source' => 'llm',
            ],
        ], 4);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => ['vehicleType' => 'cv'],
        ], 5);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame(1, $content['count']);
        $this->assertSame('IVECO', $content['vehicles'][0]['make']);
    }

    public function testListVehiclesFiltersByModelForPresenceCheckRegardlessOfType(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Stored as groundscare. The presence check must find it without the caller
        // having to guess the vehicleType (the get_vehicle_facts fragility this avoids).
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

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => ['make' => 'toro', 'model' => '246-h'],
        ], 4);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame(1, $content['count']);
        $this->assertSame('246-H', $content['vehicles'][0]['model']);
        $this->assertSame('groundscare', $content['vehicles'][0]['vehicleType']);
    }

    public function testListVehiclesReturnsEmptyOnFreshGarage(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 3);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame(0, $content['count']);
        $this->assertSame([], $content['vehicles']);
    }

    public function testTotalMissReturnsNotInCatalogResult(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'keys' => ['oil_type', 'oil_capacity'],
            ],
        ], 3);

        $content = $result['result']['structuredContent'];
        $this->assertSame('not_in_catalog', $content['status']);
        $this->assertSame('TORO', $content['vehicle']['make']);
        $this->assertSame('246-H', $content['vehicle']['model']);
        $this->assertSame(['oil_type', 'oil_capacity'], $content['missing']);
        $this->assertSame('add_vehicle_fact', $content['how_to_add']['tool']);
        $this->assertArrayHasKey('schema', $content['how_to_add']);
    }

    public function testAddFactThenRequeryReturnsHitWithProvenance(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $add = $this->call($client, $sessionId, 'tools/call', [
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
        $this->assertSame('ok', $add['result']['structuredContent']['status']);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'toro',
                'model' => '246-h',
                'vehicleType' => 'groundscare',
                'keys' => ['oil_type'],
            ],
        ], 4);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);

        $facts = $content['facts'];
        $oilType = $this->factByKey($facts, 'oil_type');
        $this->assertSame('SAE 5W-30', $oilType['value']);
        $this->assertSame('llm', $oilType['source']);
        $this->assertFalse($oilType['verified']);
        $this->assertArrayHasKey('addedAt', $oilType);
    }

    public function testWriteThroughPersistsUpstreamFactsAndServesFromGarage(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $first = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'carId' => 34247,
                'keys' => ['oil_capacity'],
            ],
        ], 3);

        $firstContent = $first['result']['structuredContent'];
        $this->assertSame('ok', $firstContent['status']);
        $firstFact = $firstContent['facts'][0];
        $this->assertSame('partsapi', $firstFact['source']);
        $this->assertTrue($firstFact['verified']);

        $callsAfterFirst = self::partsApiCache()->getItem('calls.GetFillVolumes')->get();

        $second = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'carId' => 34247,
            ],
        ], 4);

        $this->assertSame('ok', $second['result']['structuredContent']['status']);

        $callsAfterSecond = self::partsApiCache()->getItem('calls.GetFillVolumes')->get();
        $this->assertSame(
            $callsAfterFirst,
            $callsAfterSecond,
            'The second query must be served from the garage, not re-fetched upstream.',
        );
    }

    public function testVerifiedFactIsNotOverwrittenByLlmInput(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Seed a verified partsapi fact via the write-through path.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'carId' => 34247,
            ],
        ], 3);

        $refusal = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'key' => 'oil_capacity',
                'value' => '9000',
                'unit' => 'liter',
                'source' => 'llm',
            ],
        ], 4);

        $content = $refusal['result']['structuredContent'];
        $this->assertSame('refused', $content['status']);
        $this->assertSame('oil_capacity', $content['existing']['key']);
        $this->assertSame('partsapi', $content['existing']['source']);
        $this->assertTrue($content['existing']['verified']);
        $this->assertNotSame('9000', $content['existing']['value']);

        // The fact must be unchanged when re-read.
        $reread = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv', 'keys' => ['oil_capacity']],
        ], 5);

        $fact = $this->factByKey($reread['result']['structuredContent']['facts'], 'oil_capacity');
        $this->assertSame('partsapi', $fact['source']);
        $this->assertTrue($fact['verified']);
        $this->assertNotSame('9000', $fact['value']);
    }

    public function testYearQualifiedFactIsFoundOnRequery(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'year' => 2020,
                'vehicleType' => 'groundscare',
                'key' => 'oil_type',
                'value' => 'SAE 5W-30',
                'source' => 'llm',
            ],
        ], 3);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'year' => 2020,
                'vehicleType' => 'groundscare',
                'keys' => ['oil_type'],
            ],
        ], 4);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status'], 'A year-qualified fact must be findable on re-read with the same year.');
        $this->assertSame('SAE 5W-30', $this->factByKey($content['facts'], 'oil_type')['value']);
    }

    public function testReAddingSameKeyUpdatesInsteadOfDuplicating(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        foreach (['SAE 5W-30', 'SAE 0W-20'] as $id => $value) {
            $this->call($client, $sessionId, 'tools/call', [
                'name' => 'add_vehicle_fact',
                'arguments' => [
                    'make' => 'TORO',
                    'model' => '246-H',
                    'vehicleType' => 'groundscare',
                    'key' => 'oil_type',
                    'value' => $value,
                    'source' => 'llm',
                ],
            ], 3 + $id);
        }

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'keys' => ['oil_type'],
            ],
        ], 5);

        $facts = $result['result']['structuredContent']['facts'];
        $oilType = array_values(array_filter($facts, static fn (array $f): bool => 'oil_type' === $f['key']));
        $this->assertCount(1, $oilType, 'Re-adding the same key must update the fact, not insert a duplicate.');
        $this->assertSame('SAE 0W-20', $oilType[0]['value']);
    }

    public function testWebSourcedFactSurfacesUrlAndAttribution(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'key' => 'oil_capacity',
                'value' => '1.9',
                'unit' => 'liter',
                'source' => 'web',
                'url' => 'https://www.toro.com/manual/246-H',
                'addedBy' => 'support-agent',
            ],
        ], 3);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'keys' => ['oil_capacity'],
            ],
        ], 4);

        $fact = $this->factByKey($result['result']['structuredContent']['facts'], 'oil_capacity');
        $this->assertSame('web', $fact['source']);
        $this->assertSame('https://www.toro.com/manual/246-H', $fact['url']);
        $this->assertSame('support-agent', $fact['addedBy']);
    }

    public function testVehicleTypeIsPartOfIdentity(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Same make+model, two different segments. They must not share a garage row.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'AMBIGUOUS',
                'model' => 'X1',
                'vehicleType' => 'groundscare',
                'key' => 'oil_type',
                'value' => 'GROUNDSCARE-OIL',
                'source' => 'llm',
            ],
        ], 3);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'add_vehicle_fact',
            'arguments' => [
                'make' => 'AMBIGUOUS',
                'model' => 'X1',
                'vehicleType' => 'car',
                'key' => 'oil_type',
                'value' => 'CAR-OIL',
                'source' => 'llm',
            ],
        ], 4);

        $groundsCare = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => ['make' => 'AMBIGUOUS', 'model' => 'X1', 'vehicleType' => 'groundscare'],
        ], 5);

        $facts = $groundsCare['result']['structuredContent']['facts'];
        $this->assertCount(1, $facts, 'A different vehicleType must resolve to a different garage row.');
        $this->assertSame('GROUNDSCARE-OIL', $this->factByKey($facts, 'oil_type')['value']);
    }

    /**
     * @param list<array<string, mixed>> $facts
     *
     * @return array<string, mixed>
     */
    private function factByKey(array $facts, string $key): array
    {
        foreach ($facts as $fact) {
            if ($key === $fact['key']) {
                return $fact;
            }
        }

        $this->fail(sprintf('No fact with key "%s" in %s', $key, json_encode($facts)));
    }

    /**
     * @param list<array<string, mixed>> $vehicles
     *
     * @return array<string, mixed>
     */
    private function vehicleByMake(array $vehicles, string $make): array
    {
        foreach ($vehicles as $vehicle) {
            if ($make === $vehicle['make']) {
                return $vehicle;
            }
        }

        $this->fail(sprintf('No vehicle with make "%s" in %s', $make, json_encode($vehicles)));
    }
}
