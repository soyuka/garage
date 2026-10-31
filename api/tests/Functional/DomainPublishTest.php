<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\RecordingHub;

/**
 * The data-carrying live channel: a garage write must push the vehicle read-model over Mercure
 * (scalars + facts), so the dashboard renders the fresh state without a follow-up GET. The open
 * checklist is not part of this payload — it is its own subresource the dashboard refetches.
 * Replaces the old notification-only Doctrine listener. Also pins parity between the pushed payload
 * and the HTTP item GET so the two representations can never drift.
 */
final class DomainPublishTest extends AppTestCase
{
    use McpClientTrait;

    public function testPlanMaintenancePushesTheFreshVehicle(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $plan = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'plan_maintenance',
            'arguments' => [
                'make' => 'MAZDA',
                'model' => 'MAZDA6',
                'confirmNew' => true,
                'type' => 'oil_change',
                'targetUsageValue' => 250000,
                'targetUsageUnit' => 'km',
            ],
        ], 3)['result']['structuredContent'];

        $vehicleIri = $plan['task']['vehicle'];

        // The write fanned out a data-carrying 'vehicle' frame whose resource holds the checklist.
        $hub = static::getContainer()->get(RecordingHub::class);
        $vehicleFrames = array_values(array_filter(
            array_map(static fn ($u) => json_decode($u->getData(), true), $hub->updates),
            static fn ($p) => 'vehicle' === ($p['kind'] ?? null) && isset($p['resource']),
        ));

        $this->assertNotEmpty($vehicleFrames, 'a write must publish a data-carrying vehicle frame');
        $resource = $vehicleFrames[0]['resource'];
        $this->assertSame($vehicleIri, $resource['@id']);
        $this->assertSame('MAZDA', $resource['make']);

        // The open checklist rides its own subresource, not the vehicle frame.
        $this->assertArrayNotHasKey('plannedTasks', $resource);

        // Parity: the push carries everything the HTTP item GET returns, value-for-value. We compare
        // over the GET's keys (it is the authoritative set): the GET omits empty/null fields the
        // manual push still lists, so the push is a superset — what the GET does return must match.
        $get = $client->request('GET', $vehicleIri, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        foreach ($get as $key => $value) {
            // Skip the JSON-LD envelope and the duplicate `id` (the push keys identity via `@id`).
            if (\in_array($key, ['@context', '@type', 'id'], true)) {
                continue;
            }
            $this->assertEquals($value, $resource[$key] ?? null, \sprintf('GET "%s" must match the push', $key));
        }
    }
}
