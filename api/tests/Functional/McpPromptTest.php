<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpPromptTest extends AppTestCase
{
    use McpClientTrait;

    public function testFillVehicleInfoPromptIsDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $prompts = $this->call($client, $sessionId, 'prompts/list', [], 2)['result']['prompts'];

        $byName = [];
        foreach ($prompts as $prompt) {
            $byName[$prompt['name']] = $prompt;
        }

        $this->assertArrayHasKey('fill_vehicle_info', $byName);
        $argNames = array_column($byName['fill_vehicle_info']['arguments'], 'name');
        $this->assertContains('make', $argNames);
        $this->assertContains('model', $argNames);
        $this->assertContains('vehicleType', $argNames);
    }

    public function testGarageQuickActionPromptsAreDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $names = array_column($this->call($client, $sessionId, 'prompts/list', [], 2)['result']['prompts'], 'name');

        $this->assertContains('list_vehicles', $names);
        $this->assertContains('whats_due', $names);
        $this->assertContains('next_to_maintain', $names);
        $this->assertContains('identify_plate', $names);
        $this->assertContains('log_service', $names);
        $this->assertContains('parts_for_service', $names);
    }

    public function testPartsForServicePromptSeedsBothFluidsAndParts(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'prompts/get', [
            'name' => 'parts_for_service',
            'arguments' => ['plate' => 'XB301ZB'],
        ], 3);

        $text = $result['result']['messages'][0]['content']['text'];
        $this->assertStringContainsString('XB301ZB', $text);
        $this->assertStringContainsString('lookup_plate', $text);
        $this->assertStringContainsString('get_vehicle_facts', $text);
        $this->assertStringContainsString('get_motul_oil', $text);
        $this->assertStringNotContainsString('get_fill_volumes', $text);
        $this->assertStringContainsString('list_part_categories', $text);
        $this->assertStringContainsString('list_articles', $text);
    }

    public function testListVehiclesPromptSeedsTheListTool(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'prompts/get', [
            'name' => 'list_vehicles',
            'arguments' => ['make' => 'IVECO'],
        ], 3);

        $text = $result['result']['messages'][0]['content']['text'];
        $this->assertStringContainsString('list_vehicles', $text);
        $this->assertStringContainsString('IVECO', $text);
    }

    public function testWhatsDuePromptSeedsTheForecastTool(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'prompts/get', [
            'name' => 'whats_due',
            'arguments' => ['withinDays' => '30'],
        ], 3);

        $text = $result['result']['messages'][0]['content']['text'];
        $this->assertStringContainsString('list_due_maintenance', $text);
        $this->assertStringContainsString('30', $text);
    }

    public function testNextToMaintainPromptSeedsASingleResultForecast(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'prompts/get', [
            'name' => 'next_to_maintain',
            'arguments' => [],
        ], 3);

        $text = $result['result']['messages'][0]['content']['text'];
        $this->assertStringContainsString('list_due_maintenance', $text);
        $this->assertStringContainsString('limit', $text);
    }

    public function testIdentifyPlatePromptEmbedsThePlateAndPointsAtLookup(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'prompts/get', [
            'name' => 'identify_plate',
            'arguments' => ['plate' => 'XC302ZC'],
        ], 3);

        $text = $result['result']['messages'][0]['content']['text'];
        $this->assertStringContainsString('XC302ZC', $text);
        $this->assertStringContainsString('lookup_plate', $text);
        $this->assertStringContainsString('set_vehicle_facts', $text);
    }

    public function testLogServicePromptEmbedsVehicleAndPointsAtLogTool(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'prompts/get', [
            'name' => 'log_service',
            'arguments' => ['make' => 'IVECO', 'model' => 'DAILY', 'vehicleType' => 'cv'],
        ], 3);

        $text = $result['result']['messages'][0]['content']['text'];
        $this->assertStringContainsString('IVECO', $text);
        $this->assertStringContainsString('DAILY', $text);
        $this->assertStringContainsString('log_maintenance', $text);
    }

    public function testGetPromptEmbedsTheVehicleAndPointsAtTheTools(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'prompts/get', [
            'name' => 'fill_vehicle_info',
            'arguments' => ['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare'],
        ], 3);

        $messages = $result['result']['messages'];
        $this->assertNotEmpty($messages);
        $this->assertSame('user', $messages[0]['role']);

        $text = $messages[0]['content']['text'];
        // The seed must name the concrete vehicle…
        $this->assertStringContainsString('TORO', $text);
        $this->assertStringContainsString('246-H', $text);
        $this->assertStringContainsString('groundscare', $text);
        // …and steer the model through the existing tools to find + fill the gaps.
        $this->assertStringContainsString('get_vehicle_facts', $text);
        $this->assertStringContainsString('list_due_maintenance', $text);
        $this->assertStringContainsString('set_vehicle_facts', $text);
        $this->assertStringContainsString('log_maintenance', $text);
    }

    public function testVehicleTypeIsOptionalAndDefaultsToCar(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'prompts/get', [
            'name' => 'fill_vehicle_info',
            'arguments' => ['make' => 'MAZDA', 'model' => '6'],
        ], 3);

        $text = $result['result']['messages'][0]['content']['text'];
        $this->assertStringContainsString('MAZDA', $text);
        $this->assertStringContainsString('car', $text);
    }
}
