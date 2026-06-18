<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\ODM\MongoDB\DocumentManager;

final class McpGarageTest extends AppTestCase
{
    use McpClientTrait;

    public function testGarageToolsAreDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        $this->assertContains('get_vehicle_facts', $tools);
        $this->assertContains('list_vehicles', $tools);
        $this->assertNotContains('add_vehicle_fact', $tools, 'Facts are recorded via plan_maintenance attributes — there is no add-fact tool.');
    }

    public function testListVehiclesReturnsStoredVehiclesWithFactCount(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Seed two distinct vehicles in the shared garage.
        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 5W-30',
            'source' => 'llm',
        ], 3);
        $this->planFact($client, $sessionId, [
            'make' => 'IVECO',
            'model' => 'DAILY',
            'vehicleType' => 'cv',
            'key' => 'oil_type',
            'value' => 'SAE 5W-40',
            'source' => 'llm',
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

        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 5W-30',
            'source' => 'llm',
        ], 3);
        $this->planFact($client, $sessionId, [
            'make' => 'IVECO',
            'model' => 'DAILY',
            'vehicleType' => 'cv',
            'key' => 'oil_type',
            'value' => 'SAE 5W-40',
            'source' => 'llm',
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
        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 5W-30',
            'source' => 'llm',
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
        $this->assertSame('plan_maintenance', $content['how_to_add']['tool']);
        $this->assertArrayHasKey('hint', $content['how_to_add']);
    }

    public function testAddFactThenRequeryReturnsHitWithProvenance(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $add = $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 5W-30',
            'source' => 'llm',
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

    public function testVerifiedFactWinsOverAgentFactForTheSameKey(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Seed a verified partsapi fact via the write-through path (lands on the catalog_specs item).
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'carId' => 34247,
            ],
        ], 3);

        // The agent records a contradicting llm value on a service item. The write succeeds — there
        // is no refusal anymore — but the projection must still surface the VERIFIED catalog value.
        $plan = $this->planFact($client, $sessionId, [
            'make' => 'IVECO',
            'model' => 'DAILY',
            'vehicleType' => 'cv',
            'key' => 'oil_capacity',
            'value' => '9000',
            'unit' => 'liter',
            'source' => 'llm',
        ], 4);
        $this->assertSame('ok', $plan['result']['structuredContent']['status']);

        $reread = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv', 'keys' => ['oil_capacity']],
        ], 5);

        $fact = $this->factByKey($reread['result']['structuredContent']['facts'], 'oil_capacity');
        $this->assertSame('partsapi', $fact['source'], 'The verified catalog value must win the projection.');
        $this->assertTrue($fact['verified']);
        $this->assertNotSame('9000', $fact['value']);
    }

    public function testYearQualifiedFactIsFoundOnRequery(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'year' => 2020,
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 5W-30',
            'source' => 'llm',
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
            $this->planFact($client, $sessionId, [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'key' => 'oil_type',
                'value' => $value,
                'source' => 'llm',
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

        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_capacity',
            'value' => '1.9',
            'unit' => 'liter',
            'source' => 'web',
            'url' => 'https://www.toro.com/manual/246-H',
            'addedBy' => 'support-agent',
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
        $this->planFact($client, $sessionId, [
            'make' => 'AMBIGUOUS',
            'model' => 'X1',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'GROUNDSCARE-OIL',
            'source' => 'llm',
        ], 3);

        $this->planFact($client, $sessionId, [
            'make' => 'AMBIGUOUS',
            'model' => 'X1',
            'vehicleType' => 'car',
            'key' => 'oil_type',
            'value' => 'CAR-OIL',
            'source' => 'llm',
        ], 4);

        $groundsCare = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => ['make' => 'AMBIGUOUS', 'model' => 'X1', 'vehicleType' => 'groundscare'],
        ], 5);

        $facts = $groundsCare['result']['structuredContent']['facts'];
        $this->assertCount(1, $facts, 'A different vehicleType must resolve to a different garage row.');
        $this->assertSame('GROUNDSCARE-OIL', $this->factByKey($facts, 'oil_type')['value']);
    }

    public function testListVehiclesSurfacesDereferenceableVehicleIri(): void
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

        $list = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 4)['result']['structuredContent'];

        $iri = $list['vehicles'][0]['@id'] ?? null;
        $this->assertNotNull($iri, 'list_vehicles must surface a dereferenceable @id, not a leaked DB integer.');
        $this->assertMatchesRegularExpression('~^/garage/vehicles/[0-9A-HJKMNP-TV-Z]{26}$~', $iri);

        // The @id must be a real, dereferenceable link — follow it to a 200 with matching identity.
        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare']);
    }

    public function testVehicleFactIsInlinedWithProvenanceAndHasNoOwnIri(): void
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

        $facts = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => ['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare'],
        ], 4)['result']['structuredContent']['facts'];

        // Facts are projected from the vehicle's items: the value + full provenance travel inline, and
        // a fact has no own dereferenceable IRI (no @id, no /garage/vehicle-facts endpoint).
        $oilType = $this->factByKey($facts, 'oil_type');
        $this->assertNotNull($oilType);
        $this->assertSame('SAE 5W-30', $oilType['value']);
        $this->assertSame('llm', $oilType['source']);
        $this->assertFalse($oilType['verified']);
        $this->assertArrayNotHasKey('@id', $oilType);
        $this->assertArrayNotHasKey('id', $oilType);
    }

    public function testAmbiguousMakeSurfacesExistingVehicleAsCandidateInsteadOfCreating(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Stored as TORO 246-H. Toro acquired Wheel Horse — the 246-H is the same machine.
        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 5W-30',
            'source' => 'llm',
        ], 3);

        // Adding under the other brand string must NOT silently create a second row:
        // same vehicleType + same model is a candidate the agent must confront.
        $add = $this->planFact($client, $sessionId, [
            'make' => 'Wheel Horse',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'engine',
            'value' => 'Briggs & Stratton 303777',
            'source' => 'user',
        ], 4)['result']['structuredContent'];

        $this->assertSame('ambiguous_match', $add['status']);
        $this->assertCount(1, $add['candidates']);
        $this->assertSame('TORO', $add['candidates'][0]['make']);
        $this->assertSame('246-H', $add['candidates'][0]['model']);
        $this->assertMatchesRegularExpression('~^/garage/vehicles/[0-9A-HJKMNP-TV-Z]{26}$~', $add['candidates'][0]['@id']);

        // The store must be untouched — still a single vehicle, still its one fact.
        $list = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 5)['result']['structuredContent'];
        $this->assertSame(1, $list['count'], 'An ambiguous add must not create a second row.');
        $this->assertSame(1, $list['vehicles'][0]['factCount']);
    }

    public function testWritingByVehicleIriFoldsFactsOntoTheExistingRow(): void
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

        // Resolve the ambiguity by addressing the existing row directly via its @id.
        $add = $this->planFact($client, $sessionId, [
            'vehicle' => $iri,
            'key' => 'engine',
            'value' => 'Briggs & Stratton 303777',
            'source' => 'user',
        ], 5)['result']['structuredContent'];
        $this->assertSame('ok', $add['status']);

        $list = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 6)['result']['structuredContent'];
        $this->assertSame(1, $list['count'], 'Writing by id must fold onto the existing row, not create.');
        $this->assertSame(2, $list['vehicles'][0]['factCount']);
    }

    public function testConfirmNewCreatesDistinctRowDespiteCandidate(): void
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

        // The agent decided it is genuinely a different machine: confirmNew overrides.
        $add = $this->planFact($client, $sessionId, [
            'make' => 'Wheel Horse',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'confirmNew' => true,
            'key' => 'engine',
            'value' => 'Briggs & Stratton 303777',
            'source' => 'user',
        ], 4)['result']['structuredContent'];
        $this->assertSame('ok', $add['status']);

        $list = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 5)['result']['structuredContent'];
        $this->assertSame(2, $list['count'], 'confirmNew must create the distinct row.');
    }

    public function testWritingByCarIdSurfacesExistingCatalogVehicleAsCandidate(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Seed a catalog vehicle carrying its kType via the write-through path.
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'carId' => 34247,
            ],
        ], 3);

        // A second add for the same carId under a different model label must be caught.
        $add = $this->planFact($client, $sessionId, [
            'make' => 'IVECO',
            'model' => 'DAILY III Van',
            'vehicleType' => 'cv',
            'carId' => 34247,
            'key' => 'engine_code',
            'value' => 'F1AE0481B',
            'source' => 'web',
        ], 4)['result']['structuredContent'];

        $this->assertSame('ambiguous_match', $add['status']);
        $this->assertSame(34247, $add['candidates'][0]['kType']);
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

    public function testGetVehicleFactsByVehicleIri(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Seed a vehicle carrying a fact.
        $this->planFact($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_capacity',
            'value' => '1.4',
            'unit' => 'liter',
            'source' => 'llm',
        ], 3);

        $iri = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 4)['result']['structuredContent']['vehicles'][0]['@id'];

        // Read its facts by the @id alone — the selected-vehicle handle, no make/model.
        $content = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => ['vehicle' => $iri, 'keys' => ['oil_capacity']],
        ], 5)['result']['structuredContent'];

        $this->assertSame('ok', $content['status']);
        $this->assertSame($iri, $content['vehicle']['@id']);
        $this->assertSame('oil_capacity', $content['facts'][0]['key']);
    }

    public function testGetVehicleFactsByIriBackfillsCatalogSpecsWhenKTypeKnown(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // A user-created row carrying a kType but no spec sheet — exactly what plate lookup leaves.
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $vehicle = new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'user', year: 2007, kType: 19107);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
        $iri = '/garage/vehicles/'.$vehicle->getId();

        // Reading facts by @id alone (the dashboard's selected-vehicle handle) backfills the catalog
        // fill volumes from the kType, so the oil capacity is no longer missing.
        $content = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => ['vehicle' => $iri, 'keys' => ['oil_capacity']],
        ], 3)['result']['structuredContent'];

        $this->assertSame('ok', $content['status']);
        $oilCapacity = $this->factByKey($content['facts'], 'oil_capacity');
        $this->assertSame('4.3', $oilCapacity['value']);
        $this->assertSame('partsapi', $oilCapacity['source']);
        $this->assertTrue($oilCapacity['verified']);
    }

    public function testGetVehicleFactsByUnknownIriIsNotFound(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_vehicle_facts',
            'arguments' => ['vehicle' => '/garage/vehicles/01JZZZZZZZZZZZZZZZZZZZZZZZ'],
        ], 3)['result']['structuredContent'];

        $this->assertSame('not_found', $content['status']);
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
