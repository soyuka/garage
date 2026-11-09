<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Mcp\Agent\McpToolbox;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * The dashboard's editable card writes a vehicle's own scalars (usage reading, plate) straight to
 * its IRI with a JSON Merge Patch — no agent round-trip, no dedup (the vehicle is already
 * identified). Identity fields stay read-only; the write fans out over Mercure like any other.
 */
final class GarageVehiclePatchTest extends AppTestCase
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

        $member = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])
            ->toArray()['hydra:member'];

        $iri = $member[0]['@id'];
        static::getContainer()->get(McpToolbox::class)->execute(new ToolCall('seed-facts', 'set_vehicle_facts', [
            'vehicle' => $iri,
            'facts' => [['key' => 'oil_type', 'value' => 'SAE 5W-30', 'source' => 'llm']],
        ]));

        return $iri;
    }

    public function testPatchSetsUsageAndPlate(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['usageValue' => 142000, 'usageUnit' => 'km', 'plate' => 'AB-123-CD'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['usageValue' => 142000, 'usageUnit' => 'km', 'plate' => 'AB-123-CD']);

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertJsonContains(['usageValue' => 142000, 'usageUnit' => 'km', 'plate' => 'AB-123-CD']);
    }

    public function testPatchCanCorrectIdentityFields(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['make' => 'FORD', 'model' => 'FOCUS', 'plate' => 'AB-123-CD'],
        ]);

        $this->assertResponseIsSuccessful();
        // A human editing their own card may correct identity too (the agent's dedup path is separate).
        $this->assertJsonContains(['make' => 'FORD', 'model' => 'FOCUS', 'plate' => 'AB-123-CD']);
    }

    public function testPatchCannotForgeProvenance(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);
        $source = $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']])->toArray()['source'];

        $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['source' => 'siv', 'plate' => 'AB-123-CD'],
        ]);

        $this->assertResponseIsSuccessful();
        // source is provenance (writable: false), not a card field: the body's "siv" is ignored.
        $this->assertJsonContains(['source' => $source, 'plate' => 'AB-123-CD']);
    }

    public function testUsageValueWithoutUnitIsRejected(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('PATCH', $iri, [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['usageValue' => 142000],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testItemGetExposesFactsWithProvenance(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'facts' => [['key' => 'oil_type', 'value' => 'SAE 5W-30', 'source' => 'llm', 'verified' => false]],
        ]);
    }
}
