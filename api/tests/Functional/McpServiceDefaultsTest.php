<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\Client;

/**
 * The read-only `list_service_defaults` tool: the generic-baseline catalogue the agent must consult
 * BEFORE web-searching an interval. The contract these tests pin is that an unknown discriminant
 * yields a QUESTION (ambiguous + the fact key) and never a number.
 */
final class McpServiceDefaultsTest extends AppTestCase
{
    use McpClientTrait;

    public function testTheToolIsAdvertisedAsReadOnly(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = $this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'];
        $tool = $this->toolByName($tools, 'list_service_defaults');

        $this->assertTrue($tool['annotations']['readOnlyHint']);
        $this->assertStringContainsString('BEFORE', $tool['description']);
    }

    public function testListingWithoutArgumentsReturnsTheWholeCatalogue(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->defaults($client, $sessionId, [], 2);

        $this->assertSame('ok', $content['status']);
        $this->assertContains('timing_belt', $content['types']);
        $this->assertContains('brake_fluid', $content['types']);
        $this->assertSame(\count($content['baselines']), $content['count']);
        $this->assertNotEmpty($content['notes']);
    }

    public function testTheDistributionIsAmbiguousWithNoVehicle(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->defaults($client, $sessionId, ['type' => 'timing_belt'], 2);

        $this->assertSame('ambiguous', $content['status']);
        $this->assertSame('distribution_type', $content['discriminantKey']);
        $this->assertNull($content['baseline']);
        $this->assertCount(2, $content['candidates']);

        // The chain candidate must carry NO interval — that absence is the answer, not a data hole.
        $chain = $this->candidateMatching($content['candidates'], 'chain');
        $this->assertFalse($chain['hasInterval']);
        $this->assertNull($chain['everyUsageValue']);
        $this->assertNull($chain['everyMonths']);
    }

    public function testAServiceWithNoDiscriminantResolvesStraightAway(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->defaults($client, $sessionId, ['type' => 'brake_fluid'], 2);

        $this->assertSame('resolved', $content['status']);
        $this->assertSame(24, $content['baseline']['everyMonths']);
        $this->assertNull($content['baseline']['everyUsageValue']);
        $this->assertNull($content['discriminantKey']);
    }

    public function testTheDiscriminantIsReadFromTheVehiclesOwnFacts(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $iri = $this->seedVehicle($client, $sessionId, [
            'make' => 'MAZDA',
            'model' => '6',
            'key' => 'distribution_type',
            'value' => 'timing chain',
        ]);

        $content = $this->defaults($client, $sessionId, ['type' => 'timing_belt', 'vehicle' => $iri], 5);

        $this->assertSame('resolved', $content['status']);
        $this->assertSame('timing chain', $content['discriminantValue']);
        $this->assertFalse($content['baseline']['hasInterval']);
        $this->assertSame($iri, $content['vehicle']['@id']);
    }

    public function testTheSegmentScopesTheCadenceToEngineHours(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $iri = $this->seedVehicle($client, $sessionId, [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'key' => 'oil_type',
            'value' => 'SAE 30',
        ]);

        $content = $this->defaults($client, $sessionId, ['type' => 'oil_change', 'vehicle' => $iri], 5);

        $this->assertSame('resolved', $content['status']);
        $this->assertSame(50, $content['baseline']['everyUsageValue']);
        $this->assertSame('hours', $content['baseline']['everyUsageUnit']);
    }

    public function testAServiceOutsideTheCatalogueIsUnknownNotAnError(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->defaults($client, $sessionId, ['type' => 'gearbox_oil'], 2);

        $this->assertSame('unknown', $content['status']);
        $this->assertSame([], $content['candidates']);
        $this->assertNotSame('', $content['reason']);
    }

    public function testABadVehicleHandleIsAResultNotAnError(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->defaults($client, $sessionId, ['type' => 'oil_change', 'vehicle' => 'not-an-iri'], 2);

        $this->assertSame('invalid_input', $content['status']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function defaults(Client $client, string $sessionId, array $arguments, int $id): array
    {
        return $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_service_defaults',
            'arguments' => $arguments,
        ], $id)['result']['structuredContent'];
    }

    /**
     * Seed one vehicle carrying one fact, and return its @id.
     *
     * @param array<string, mixed> $args
     */
    private function seedVehicle(Client $client, string $sessionId, array $args): string
    {
        $this->planFact($client, $sessionId, $args, 2);

        $list = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 3)['result']['structuredContent'];

        return $list['vehicles'][0]['@id'];
    }

    /**
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     */
    private function toolByName(array $tools, string $name): array
    {
        foreach ($tools as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }

        $this->fail(sprintf('Tool "%s" is not advertised by the MCP server.', $name));
    }

    /**
     * @param list<array<string, mixed>> $candidates
     *
     * @return array<string, mixed>
     */
    private function candidateMatching(array $candidates, string $discriminantValue): array
    {
        foreach ($candidates as $candidate) {
            if (\in_array($discriminantValue, $candidate['appliesWhen']['values'] ?? [], true)) {
                return $candidate;
            }
        }

        $this->fail(sprintf('No candidate applies when the discriminant is "%s".', $discriminantValue));
    }
}
