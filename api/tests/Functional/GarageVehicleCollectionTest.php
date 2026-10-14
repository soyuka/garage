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

        static::getContainer()->get(McpToolbox::class)->execute(new ToolCall('seed', 'plan_maintenance', [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'type' => 'oil_change',
        ]));

        $response = $client->request('GET', '/garage/vehicles');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'hydra:totalItems' => 1,
            'hydra:member' => [['make' => 'TORO', 'model' => '246-H', 'vehicleType' => 'groundscare']],
        ]);
    }
}
