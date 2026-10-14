<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Agent\McpToolbox;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * The `api` firewall gates every garage WRITE (and POST /agent/turn) behind a Casdoor token,
 * mirroring the protection the MCP tools already have. Reads stay public for now (the dashboard
 * still lists/inspects vehicles anonymously and Mercure is unauthenticated — the latent
 * multi-tenant cluster is the next step). This pins the boundary: anonymous writes 401, the same
 * write with a valid token succeeds.
 */
final class GarageWriteAuthTest extends AppTestCase
{
    private function seedVehicleIri(object $client): string
    {
        static::getContainer()->get(McpToolbox::class)->execute(new ToolCall('seed', 'plan_maintenance', [
            'make' => 'MAZDA',
            'model' => 'MAZDA6',
            'year' => 2007,
            'vehicleType' => 'car',
            'type' => 'oil_change',
        ]));

        return $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])
            ->toArray()['hydra:member'][0]['@id'];
    }

    public function testAnonymousVehiclePatchIsRejected(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('PATCH', $iri, [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['usageValue' => 142000, 'usageUnit' => 'km'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAnonymousFactPostIsRejected(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('POST', $iri.'/facts', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['key' => 'tyre_size', 'value' => '205/55R16'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAnonymousAgentTurnIsRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/agent/turn', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'hello', 'sessionId' => 'anon-session'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testVehicleReadStaysPublic(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);

        $this->assertResponseIsSuccessful();
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
