<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Agent\McpToolbox;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * The dashboard's editable card writes a vehicle's own scalars (usage reading, plate) straight to
 * its IRI with a JSON Merge Patch — no agent round-trip, no dedup (the vehicle is already
 * identified). Identity fields stay read-only; the write fans out over Mercure like any other.
 */
final class GarageVehiclePatchTest extends AppTestCase
{
    private function seedVehicleIri(object $client): string
    {
        // Facts ride on a plan_maintenance item's attributes now — there is no add-fact tool.
        static::getContainer()->get(McpToolbox::class)->execute(new ToolCall('seed', 'plan_maintenance', [
            'make' => 'MAZDA',
            'model' => 'MAZDA6',
            'year' => 2007,
            'vehicleType' => 'car',
            'type' => 'oil_change',
            'attributes' => [['key' => 'oil_type', 'value' => 'SAE 5W-30', 'source' => 'llm']],
        ]));

        $member = $client->request('GET', '/garage/vehicles', ['headers' => ['Accept' => 'application/ld+json']])
            ->toArray()['member'];

        return $member[0]['@id'];
    }

    public function testPatchSetsUsageAndPlate(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('PATCH', $iri, [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['usageValue' => 142000, 'usageUnit' => 'km', 'plate' => 'AB-123-CD'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['usageValue' => 142000, 'usageUnit' => 'km', 'plate' => 'AB-123-CD']);

        $client->request('GET', $iri, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertJsonContains(['usageValue' => 142000, 'usageUnit' => 'km', 'plate' => 'AB-123-CD']);
    }

    public function testPatchCannotChangeIdentityFields(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('PATCH', $iri, [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['make' => 'FORD', 'model' => 'FOCUS', 'plate' => 'AB-123-CD'],
        ]);

        $this->assertResponseIsSuccessful();
        // Identity is read-only: the make/model in the body are ignored, only the plate lands.
        $this->assertJsonContains(['make' => 'MAZDA', 'model' => 'MAZDA6', 'plate' => 'AB-123-CD']);
    }

    public function testUsageValueWithoutUnitIsRejected(): void
    {
        $client = static::createClient();
        $iri = $this->seedVehicleIri($client);

        $client->request('PATCH', $iri, [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
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
