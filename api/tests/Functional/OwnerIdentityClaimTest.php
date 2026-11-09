<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;

/**
 * Tenancy keys on the `email` claim, not on the OIDC `sub`.
 *
 * Casdoor mints a fresh uuid for the built-in `admin` user every time it re-applies its seed
 * (verified 2026-09-19: three restarts, three different ids in the `user` table), so a `sub`-keyed
 * owner loses the whole garage on a restart. The email survives that. Pins the property the outage
 * exposed: the same person, re-authenticated under a new provider id, still owns their parc.
 */
final class OwnerIdentityClaimTest extends AppTestCase
{
    private function createVehicleAs(Client $client, string $sub, string $email): void
    {
        $client->request('POST', '/garage/vehicles', [
            'headers' => self::authHeaders($sub, $email) + ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => (object) [],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    /** @return list<array<string, mixed>> */
    private function listAs(Client $client, string $sub, string $email): array
    {
        $body = $client->request('GET', '/garage/vehicles', [
            'headers' => self::authHeaders($sub, $email) + ['Accept' => 'application/ld+json'],
        ])->toArray();

        return $body['member'] ?? $body['hydra:member'];
    }

    public function testTheParcSurvivesANewProviderIdForTheSameEmail(): void
    {
        $client = static::createClient();
        $this->createVehicleAs($client, 'casdoor-uuid-before-restart', 'owner@example.com');

        $after = $this->listAs($client, 'casdoor-uuid-after-restart', 'owner@example.com');

        $this->assertCount(1, $after);
    }

    public function testADifferentEmailStillSeesNothing(): void
    {
        $client = static::createClient();
        $this->createVehicleAs($client, 'casdoor-uuid-before-restart', 'owner@example.com');

        $this->assertSame([], $this->listAs($client, 'casdoor-uuid-before-restart', 'stranger@example.com'));
    }
}
