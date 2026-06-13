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
        foreach (['lookup_plate', 'list_makes', 'list_models', 'list_cars', 'list_part_categories', 'list_articles', 'get_fill_volumes'] as $tool) {
            $this->assertContains($tool, $tools);
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

    public function testGetFillVolumesTool(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_fill_volumes',
            'arguments' => ['carId' => 34247],
        ], 4);

        $labels = array_column($result['result']['structuredContent']['member'], 'label');
        $this->assertContains('Huile moteur (avec filtre)', $labels);
    }

    /**
     * Pins the LLM-visible failure mode of pending-token tools: currently a JSON-RPC
     * protocol-level error (-32603), NOT a CallToolResult with isError. If an
     * api-platform/mcp or mcp/sdk upgrade changes this shape, this test must fail so
     * the change is a conscious decision (see docs/TODO.md, MCP error mapping).
     */
    public function testPendingTokenToolSurfacesAsJsonRpcError(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $error = $this->callExpectingError($client, $sessionId, 'tools/call', [
            'name' => 'list_models',
            'arguments' => ['makeId' => 55],
        ], 5);

        $this->assertSame(-32603, $error['code']);
        $this->assertStringContainsString('getModels', $error['message']);
    }

    public function testUnknownPlateToolCallSurfacesAsJsonRpcError(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $error = $this->callExpectingError($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'ZZ-999-ZZ'],
        ], 6);

        $this->assertStringContainsString('No vehicle found', $error['message']);
    }
}
