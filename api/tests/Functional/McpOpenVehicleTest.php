<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * `open_vehicle` — the composite that answers « qu'est-ce que j'ai à faire sur la Mazda ? » in ONE
 * tool call. It replaces the four-call plumbing a pilot run used to need (list_vehicles to find the.
 *
 * @id → select_vehicle → list_due_maintenance → list_planned_maintenance), each of which costs a
 * model round trip that the room watches as dead air.
 */
final class McpOpenVehicleTest extends AppTestCase
{
    use McpClientTrait;
    use PilotFrameTrait;

    public function testOpenVehicleToolIsDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        $this->assertContains('open_vehicle', $tools);
    }

    public function testItResolvesByNameSelectsTheCarAndReturnsTheWholePicture(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $sessionId = $this->initialize($client);

        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'confirmNew' => true,
                'type' => 'timing_belt',
                'label' => 'courroie de distribution',
                'targetUsageValue' => 250000,
                'targetUsageUnit' => 'km',
            ],
        ], 3)['result']['structuredContent'];
        $vehicleIri = $plan['task']['vehicle'];

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'open_vehicle',
            'arguments' => ['query' => 'mazda'],
        ], 4)['result']['structuredContent'];

        $this->assertSame('ok', $result['status']);
        $this->assertSame($vehicleIri, $result['vehicle']['@id']);
        $this->assertSame('MAZDA', $result['vehicle']['make']);
        // the three reads it folds in, all present in the one payload
        $this->assertArrayHasKey('due', $result);
        $this->assertArrayHasKey('gaps', $result);
        $this->assertSame(['timing_belt'], array_column($result['tasks'], 'type'));

        // …and it DROVE THE CAR IN: the same selectVehicle frame select_vehicle publishes.
        $this->assertSame(
            ['type' => 'selectVehicle', 'id' => $vehicleIri],
            $this->lastPilotFrame(),
        );
    }

    public function testItMatchesOnThePlateToo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $sessionId = $this->initialize($client);

        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'RENAULT',
                'model' => 'KANGOO',
                'confirmNew' => true,
                'plate' => 'AB-123-CD',
                'type' => 'oil_change',
            ],
        ], 3)['result']['structuredContent'];

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'open_vehicle',
            'arguments' => ['query' => 'AB-123-CD'],
        ], 4)['result']['structuredContent'];

        $this->assertSame('ok', $result['status']);
        $this->assertSame($plan['task']['vehicle'], $result['vehicle']['@id']);
    }

    public function testAnUnknownVehicleIsAResultNotAnError(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'open_vehicle',
            'arguments' => ['query' => 'lamborghini'],
        ], 3)['result'];

        $this->assertFalse($result['isError'] ?? false);
        $this->assertSame('no_match', $result['structuredContent']['status']);
    }

    public function testSeveralMatchesTakeTheMostRecentlyTouchedAndNameTheOthers(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => ['make' => 'MAZDA', 'model' => 'MAZDA6', 'confirmNew' => true, 'type' => 'oil_change'],
        ], 3);
        $second = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => ['make' => 'MAZDA', 'model' => 'MX-5', 'confirmNew' => true, 'type' => 'oil_change'],
        ], 4)['result']['structuredContent'];

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'open_vehicle',
            'arguments' => ['query' => 'mazda'],
        ], 5)['result']['structuredContent'];

        // Never a clarifying question mid-run: the most-recently-touched wins and the runner-up is
        // NAMED so the pilot can say which one it took.
        $this->assertSame('ok', $result['status']);
        $this->assertSame($second['task']['vehicle'], $result['vehicle']['@id']);
        $this->assertSame(['MAZDA MAZDA6'], array_column($result['otherMatches'], 'label'));
    }
}
