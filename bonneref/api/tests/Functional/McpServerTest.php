<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpServerTest extends AppTestCase
{
    use McpClientTrait;

    public function testToolsAreDiscoverable(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/list', [], 2);

        $tools = array_column($result['result']['tools'], 'name');
        foreach (['lookup_plate', 'list_makes', 'list_models', 'list_cars', 'list_part_categories', 'list_articles'] as $tool) {
            $this->assertContains($tool, $tools);
        }
        $this->assertEqualsCanonicalizing(['lookup_plate', 'decode_vin', 'list_makes', 'list_models', 'list_cars', 'list_part_categories', 'list_articles', 'narrow_parts', 'decode_build_code', 'compare_brands', 'get_service_parts'], $tools);
    }

    public function testNoToolSpeaksOfTheGarageOrItsTools(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $this->assertDoesNotMatchRegularExpression('/garage|propose_actions|the task/i', $tool['description'] ?? '', $tool['name']);
        }
    }

    /**
     * Server-wide invariant for Claude connector-directory submission: every tool must carry a
     * human-readable title and a readOnlyHint. Guards future tools too — adding one without
     * annotations breaks this. Behaviour-specific hints (destructive/openWorld) checked inline.
     */
    public function testEveryToolCarriesTitleAndReadOnlyHint(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = [];
        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        foreach ($tools as $name => $tool) {
            $this->assertNotEmpty($tool['title'] ?? null, "{$name} must carry a title");
            $this->assertArrayHasKey('readOnlyHint', $tool['annotations'] ?? [], "{$name} must declare readOnlyHint");
        }

        // lookup_plate queries an external registry (SIV) → open world.
        $this->assertTrue($tools['lookup_plate']['annotations']['openWorldHint'] ?? null);
        // The catalogue server is read-only end to end.
        foreach ($tools as $name => $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint'], "{$name} must be read-only");
        }
    }

    public function testLookupPlateTool(): void
    {
        $client = static::createClient();
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
     * Domain errors reach the model as a CallToolResult it can read and act on, NOT as a
     * JSON-RPC -32603 protocol error whose message the agent layer discards
     * (Symfony\AI ToolExecutionException::getToolCallResult() throws the message away, so
     * every failure otherwise collapses to the same opaque sentence).
     */
    public function testMissingApiKeySurfacesAsAStructuredResultWithoutLeakingTheEnvVar(): void
    {
        $previous = $_SERVER['API_PLAQUE_TOKEN'] ?? '';
        $_SERVER['API_PLAQUE_TOKEN'] = $_ENV['API_PLAQUE_TOKEN'] = '';

        try {
            $client = static::createClient();
            $sessionId = $this->initialize($client);

            $result = $this->call($client, $sessionId, 'tools/call', [
                'name' => 'lookup_plate',
                'arguments' => ['plate' => 'AB-123-CD'],
            ], 5);
        } finally {
            $_SERVER['API_PLAQUE_TOKEN'] = $_ENV['API_PLAQUE_TOKEN'] = $previous;
        }

        $this->assertArrayNotHasKey('error', $result, 'a missing key is a tool error, not a protocol error');

        $content = $result['result']['structuredContent'];
        $this->assertSame('not_configured', $content['status']);
        $this->assertFalse($content['retryable'], 'no amount of retrying configures a key');
        $this->assertTrue($result['result']['isError']);

        $encoded = json_encode($result, \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('API_PLAQUE_TOKEN', $encoded, 'never leak env var names to the model');
    }

    /**
     * An unknown plate is a MISS, not a failure — same contract every read tool keeps
     * ({@see \App\Mcp\ToolResult}), so isError stays false.
     */
    public function testUnknownPlateSurfacesAsANotFoundResult(): void
    {
        $client = static::createClient();
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
     * The 2026-09-14 regression: the SIV stalled, the model was handed an opaque error, and it
     * told the user the plate did not exist. The model must now be able to tell the two apart.
     */
    public function testSivTimeoutSurfacesAsRetryableAndIsNotConfusedWithAnUnknownPlate(): void
    {
        $client = static::createClient();
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
