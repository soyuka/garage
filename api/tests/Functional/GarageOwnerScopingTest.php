<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Agent\McpToolbox;
use App\Security\OwnerContext;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Root 2 of the 2026-06-19 review: per-user tenant isolation. Every garage row carries an `owner`
 * (the authenticated Casdoor `sub`); reads are scoped to the current owner and a row created by one
 * user is invisible to another. Pins that boundary end to end.
 */
final class GarageOwnerScopingTest extends AppTestCase
{
    /** Seed one vehicle owned by $sub (the in-process write path mirrors the agent worker binding). */
    private function seedVehicleFor(string $sub, string $make, string $model): void
    {
        static::getContainer()->get(OwnerContext::class)->set($sub);

        static::getContainer()->get(McpToolbox::class)->execute(new ToolCall('seed', 'plan_maintenance', [
            'make' => $make,
            'model' => $model,
            'year' => 2007,
            'vehicleType' => 'car',
            'type' => 'oil_change',
            'attributes' => [['key' => 'oil_type', 'value' => 'SAE 5W-30', 'source' => 'llm']],
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function listAs(object $client, string $sub): array
    {
        return $client->request('GET', '/garage/vehicles', [
            'headers' => self::authHeaders($sub) + ['Accept' => 'application/ld+json'],
        ])->toArray()['member'];
    }

    public function testCollectionIsScopedToTheAuthenticatedOwner(): void
    {
        $client = static::createClient();
        $this->seedVehicleFor('alice-sub', 'MAZDA', 'MAZDA6');
        $this->seedVehicleFor('bob-sub', 'RENAULT', 'CLIO');

        $alice = $this->listAs($client, 'alice-sub');
        self::assertCount(1, $alice);
        self::assertSame('MAZDA6', $alice[0]['model']);

        $bob = $this->listAs($client, 'bob-sub');
        self::assertCount(1, $bob);
        self::assertSame('CLIO', $bob[0]['model']);
    }

    public function testCrossOwnerItemReadIs404(): void
    {
        $client = static::createClient();
        $this->seedVehicleFor('alice-sub', 'MAZDA', 'MAZDA6');

        $aliceIri = $this->listAs($client, 'alice-sub')[0]['@id'];

        $client->request('GET', $aliceIri, [
            'headers' => self::authHeaders('bob-sub') + ['Accept' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', $aliceIri, [
            'headers' => self::authHeaders('alice-sub') + ['Accept' => 'application/ld+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }
}
