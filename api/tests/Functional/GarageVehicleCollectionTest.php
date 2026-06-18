<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Agent\McpToolbox;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * The real parc read behind the dashboard sidebar: GET /garage/vehicles returns the shared garage,
 * the same projection ({@see \App\Garage\VehicleSummaryView}) the MCP list_vehicles tool exposes.
 */
final class GarageVehicleCollectionTest extends AppTestCase
{
    public function testCollectionReturnsTheSharedGarage(): void
    {
        $client = static::createClient();

        // Facts ride on a plan_maintenance item's attributes now — there is no add-fact tool.
        static::getContainer()->get(McpToolbox::class)->execute(new ToolCall('seed', 'plan_maintenance', [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'type' => 'oil_change',
            'attributes' => [['key' => 'oil_type', 'value' => 'SAE 5W-30', 'source' => 'llm']],
        ]));

        $response = $client->request('GET', '/garage/vehicles');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'totalItems' => 1,
            'member' => [['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare']],
        ]);
    }
}
