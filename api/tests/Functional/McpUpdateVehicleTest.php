<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpUpdateVehicleTest extends AppTestCase
{
    use McpClientTrait;

    public function testUpdateVehicleToolIsDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        $this->assertContains('update_vehicle', $tools);
    }

    public function testSetUsageInKilometersIsReadBackOnVehicle(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // Seed a car so update_vehicle resolves an existing row.
        $this->planFact($client, $sessionId, [
            'make' => 'IVECO',
            'model' => 'DAILY',
            'vehicleType' => 'cv',
            'key' => 'oil_type',
            'value' => 'SAE 5W-40',
            'source' => 'llm',
        ], 3);

        $update = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'usageValue' => 120000,
                'usageUnit' => 'km',
            ],
        ], 4));

        $this->assertSame('ok', $update['status']);
        $this->assertSame(120000, $update['vehicle']['usageValue']);
        $this->assertSame('km', $update['vehicle']['usageUnit']);

        // The reading must survive a round-trip: GET the vehicle IRI.
        $iri = $update['vehicle']['@id'];
        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['usageValue' => 120000, 'usageUnit' => 'km']);

        // And via list_vehicles.
        $list = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => ['make' => 'IVECO'],
        ], 5));
        $this->assertSame(120000, $list['vehicles'][0]['usageValue']);
        $this->assertSame('km', $list['vehicles'][0]['usageUnit']);
    }

    public function testModificationIsStoredAndReadBack(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->planFact($client, $sessionId, [
            'make' => 'PEUGEOT',
            'model' => '406',
            'key' => 'oil_type',
            'value' => 'ACEA A3/B3',
            'source' => 'llm',
        ], 3);

        $update = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => [
                'make' => 'PEUGEOT',
                'model' => '406',
                'confirmNew' => true,
                'engineVariant' => '2.0 16V Coupé',
            ],
        ], 4));

        $this->assertSame('ok', $update['status']);
        $this->assertSame('2.0 16V Coupé', $update['vehicle']['engineVariant']);

        // Survives a round-trip: list_vehicles surfaces the stored trim.
        $list = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => ['make' => 'PEUGEOT'],
        ], 5));
        $this->assertSame('2.0 16V Coupé', $list['vehicles'][0]['engineVariant']);
    }

    public function testPartialUpdateLeavesUsageUntouched(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->planFact($client, $sessionId, [
            'make' => 'IVECO',
            'model' => 'DAILY',
            'vehicleType' => 'cv',
            'key' => 'oil_type',
            'value' => 'SAE 5W-40',
            'source' => 'llm',
        ], 3);

        $iri = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'usageValue' => 87000,
                'usageUnit' => 'km',
            ],
        ], 4))['vehicle']['@id'];

        // Update ONLY one scalar (engineVariant) by addressing the row via its @id.
        $update = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => [
                'vehicle' => $iri,
                'engineVariant' => '35S14',
            ],
        ], 5));

        $this->assertSame('ok', $update['status']);
        $this->assertSame('35S14', $update['vehicle']['engineVariant']);
        // Owner is the authenticated sub, never a client-supplied field (review Root 2).
        $this->assertSame(self::TEST_OWNER, $update['vehicle']['owner']);
        $this->assertSame(87000, $update['vehicle']['usageValue'], 'A partial update must not clear an untouched usage reading.');
        $this->assertSame('km', $update['vehicle']['usageUnit']);
    }

    public function testGroundscareVehicleUsesEngineHours(): void
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

        $update = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => [
                'make' => 'TORO',
                'model' => '246-H',
                'vehicleType' => 'groundscare',
                'confirmNew' => true,
                'usageValue' => 320,
                'usageUnit' => 'h',
            ],
        ], 4));

        $this->assertSame('ok', $update['status']);
        $this->assertSame(320, $update['vehicle']['usageValue']);
        $this->assertSame('h', $update['vehicle']['usageUnit']);
    }

    public function testUsageValueWithoutUnitIsInvalidInput(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->planFact($client, $sessionId, [
            'make' => 'IVECO',
            'model' => 'DAILY',
            'vehicleType' => 'cv',
            'key' => 'oil_type',
            'value' => 'SAE 5W-40',
            'source' => 'llm',
        ], 3);

        $update = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'update_vehicle',
            'arguments' => [
                'make' => 'IVECO',
                'model' => 'DAILY',
                'vehicleType' => 'cv',
                'confirmNew' => true,
                'usageValue' => 120000,
            ],
        ], 4));

        $this->assertSame('invalid_input', $update['error']);
    }
}
