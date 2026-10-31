<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpClient\Exception\TransportException;

final class McpServerTest extends AppTestCase
{
    use McpClientTrait;

    public function testToolsAreDiscoverable(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/list', [], 2);

        $tools = array_column($result['result']['tools'], 'name');
        foreach (['lookup_plate', 'list_makes', 'list_models', 'list_cars', 'list_part_categories', 'list_articles'] as $tool) {
            $this->assertContains($tool, $tools);
        }
        $this->assertNotContains('get_fill_volumes', $tools);
    }

    /**
     * Server-wide invariant for Claude connector-directory submission: every tool must carry a
     * human-readable title and a readOnlyHint. Guards future tools too — adding one without
     * annotations breaks this. Behaviour-specific hints (destructive/openWorld) checked inline.
     */
    public function testEveryToolCarriesTitleAndReadOnlyHint(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        foreach ($tools as $name => $tool) {
            $this->assertNotEmpty($tool['title'] ?? null, "{$name} must carry a title");
            $this->assertArrayHasKey('readOnlyHint', $tool['annotations'] ?? [], "{$name} must declare readOnlyHint");
        }

        // lookup_plate queries an external registry (proxied through bonneref) → open world.
        $this->assertTrue($tools['lookup_plate']['annotations']['openWorldHint'] ?? null);
        // plan_maintenance writes but is additive: it upserts the open task and never deletes.
        $this->assertFalse($tools['plan_maintenance']['annotations']['readOnlyHint'] ?? null);
        $this->assertFalse($tools['plan_maintenance']['annotations']['destructiveHint'] ?? null);
    }

    public function testLookupPlateTool(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'XC-302-ZC'],
        ], 3);

        $content = $result['result']['structuredContent'];
        $this->assertSame('IVECO', $content['make']);
        $this->assertSame(34247, $content['carId']);
        $this->assertSame('CV', $content['carType']);
    }

    /**
     * An unknown plate is a MISS, not a failure — same contract the garage read tools keep
     * ({@see \App\Garage\ToolResult}), so isError stays false.
     */
    public function testUnknownPlateSurfacesAsANotFoundResult(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'ZZ-999-ZZ'],
        ], 6);

        $this->assertArrayNotHasKey('error', $result);

        $content = $result['result']['structuredContent'];
        $this->assertSame('not_found', $content['status']);
        $this->assertFalse($content['retryable']);
        $this->assertFalse($result['result']['isError'], 'a miss is a result, not an error');
    }

    /**
     * The 2026-09-14 regression: the registry stalled, the model was handed an opaque error, and it
     * told the user the plate did not exist. The model must now be able to tell the two apart.
     */
    public function testARegistryTimeoutSurfacesAsRetryableAndIsNotConfusedWithAnUnknownPlate(): void
    {
        $client = static::createClient();
        static::bonneref()->route('/vehicles/TT000TT', new TransportException('Idle timeout reached'));
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'TT-000-TT'],
        ], 7);

        $this->assertArrayNotHasKey('error', $result);

        $content = $result['result']['structuredContent'];
        $this->assertSame('upstream_unavailable', $content['status']);
        $this->assertTrue($content['retryable'], 'the whole point: a stall is retryable, an unknown plate is not');
        $this->assertTrue($result['result']['isError']);
        $this->assertNotSame('not_found', $content['status']);
    }
}
