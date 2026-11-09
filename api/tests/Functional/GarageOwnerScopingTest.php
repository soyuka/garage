<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Mcp\Agent\McpToolbox;
use App\Security\OwnerContext;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Root 2 of the 2026-06-19 review: per-user tenant isolation. Every garage row carries an `owner`
 * (the authenticated Casdoor email claim); reads are scoped to the current owner and a row created by one
 * user is invisible to another. Pins that boundary end to end.
 */
final class GarageOwnerScopingTest extends AppTestCase
{
    /** Seed one vehicle owned by $owner (the in-process write path mirrors the agent worker binding). */
    private function seedVehicleFor(string $owner, string $make, string $model): void
    {
        static::getContainer()->get(OwnerContext::class)->set($owner);

        static::getContainer()->get(McpToolbox::class)->execute(new ToolCall('seed', 'plan_maintenance', [
            'make' => $make,
            'model' => $model,
            'year' => 2007,
            'vehicleType' => 'car',
            'type' => 'oil_change',
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function listAs(Client $client, string $owner): array
    {
        return $client->request('GET', '/garage/vehicles', [
            // Since 55bf873 the owner is the email claim, so the tenant varies on the second argument.
            'headers' => self::authHeaders('sub-of-'.$owner, $owner) + ['Accept' => 'application/ld+json'],
        ])->toArray()['hydra:member'];
    }

    public function testCollectionIsScopedToTheAuthenticatedOwner(): void
    {
        $client = static::createClient();
        $this->seedVehicleFor('alice@example.com', 'MAZDA', 'MAZDA6');
        $this->seedVehicleFor('bob@example.com', 'RENAULT', 'CLIO');

        $alice = $this->listAs($client, 'alice@example.com');
        $this->assertCount(1, $alice);
        $this->assertSame('MAZDA6', $alice[0]['model']);

        $bob = $this->listAs($client, 'bob@example.com');
        $this->assertCount(1, $bob);
        $this->assertSame('CLIO', $bob[0]['model']);
    }

    public function testCrossOwnerItemReadIs404(): void
    {
        $client = static::createClient();
        $this->seedVehicleFor('alice@example.com', 'MAZDA', 'MAZDA6');

        $aliceIri = $this->listAs($client, 'alice@example.com')[0]['@id'];

        $client->request('GET', $aliceIri, [
            'headers' => self::authHeaders('sub-of-bob', 'bob@example.com') + ['Accept' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', $aliceIri, [
            'headers' => self::authHeaders('sub-of-alice', 'alice@example.com') + ['Accept' => 'application/ld+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }
}
