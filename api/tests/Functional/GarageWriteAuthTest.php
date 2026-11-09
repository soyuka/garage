<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Mcp\Agent\McpToolbox;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * The `api` firewall is deny-by-default (security.yaml): every garage read and write, and
 * POST /agent/turn, needs a Casdoor token, mirroring the protection the MCP tools already have.
 * This pins the boundary: anonymous reads and writes 401, the same write with a valid token succeeds.
 */
final class GarageWriteAuthTest extends AppTestCase
{
    private function seedVehicleIri(Client $client): string
    {
        static::getContainer()->get(McpToolbox::class)->execute(new ToolCall('seed', 'plan_maintenance', [
            'make' => 'MAZDA',
            'model' => 'MAZDA6',
            'year' => 2007,
            'vehicleType' => 'car',
            'type' => 'oil_change',
        ]));

        return $client->request('GET', '/garage/vehicles', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])
            ->toArray()['hydra:member'][0]['@id'];
    }

    public function testAnonymousVehiclePatchIsRejected(): void
    {
        $client = static::createAnonymousClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('PATCH', $iri, [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['usageValue' => 142000, 'usageUnit' => 'km'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAnonymousFactPostIsRejected(): void
    {
        $client = static::createAnonymousClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('POST', $iri.'/facts', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['key' => 'tyre_size', 'value' => '205/55R16'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAnonymousAgentTurnIsRejected(): void
    {
        $client = static::createAnonymousClient();

        $client->request('POST', '/agent/turn', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'hello', 'sessionId' => 'anon-session'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAnonymousVehicleReadIsRejected(): void
    {
        $client = static::createAnonymousClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedVehiclePatchSucceeds(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['usageValue' => 142000, 'usageUnit' => 'km'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['usageValue' => 142000, 'usageUnit' => 'km']);
    }
}
