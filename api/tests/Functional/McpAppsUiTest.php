<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The MCP Apps extension (io.modelcontextprotocol/ui) wiring: the server advertises the
 * extension at initialize, the get_maintenance_history tool links a ui:// resource via
 * _meta.ui.resourceUri, and that resource serves the maintenance-cards HTML app.
 */
final class McpAppsUiTest extends AppTestCase
{
    use McpClientTrait;

    private const UI_RESOURCE_URI = 'ui://mon-parc-auto/maintenance-history';
    private const UI_MIME_TYPE = 'text/html;profile=mcp-app';

    public function testInitializeAdvertisesAppsExtension(): void
    {
        $client = static::createClient();

        $response = $client->request('POST', '/mcp', [
            'headers' => $this->mcpHeaders(),
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-03-26',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'phpunit', 'version' => '0.0.0'],
                ],
            ],
        ]);

        $json = $this->extractJson($response->getContent());
        $extensions = $json['result']['capabilities']['extensions'] ?? [];

        $this->assertArrayHasKey('io.modelcontextprotocol/ui', $extensions);
        $this->assertContains(self::UI_MIME_TYPE, $extensions['io.modelcontextprotocol/ui']['mimeTypes'] ?? []);
    }

    public function testServerDiscoverAdvertisesAppsExtension(): void
    {
        $client = static::createClient();

        $response = $client->request('POST', '/mcp', [
            'headers' => $this->mcpHeaders(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover']),
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'server/discover',
                'params' => ['_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ]],
            ],
        ]);

        $json = $this->extractJson($response->getContent());

        $this->assertContains('2026-07-28', $json['result']['supportedVersions'] ?? []);
        $this->assertContains(self::UI_MIME_TYPE, $json['result']['capabilities']['extensions']['io.modelcontextprotocol/ui']['mimeTypes'] ?? []);
    }

    public function testMaintenanceHistoryToolLinksUiResource(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/list', [], 2);

        $tool = null;
        foreach ($result['result']['tools'] as $t) {
            if ('get_maintenance_history' === $t['name']) {
                $tool = $t;
                break;
            }
        }

        $this->assertNotNull($tool, 'get_maintenance_history tool must be listed');
        $this->assertSame(self::UI_RESOURCE_URI, $tool['_meta']['ui']['resourceUri'] ?? null);
    }

    public function testUiResourceIsListed(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'resources/list', [], 3);

        $resource = null;
        foreach ($result['result']['resources'] as $r) {
            if (self::UI_RESOURCE_URI === $r['uri']) {
                $resource = $r;
                break;
            }
        }

        $this->assertNotNull($resource, 'ui:// maintenance resource must be listed');
        $this->assertSame(self::UI_MIME_TYPE, $resource['mimeType']);
        $this->assertArrayHasKey('ui', $resource['_meta'] ?? [], 'resource descriptor must carry the _meta.ui marker');
    }

    public function testUiResourceReadServesHtmlApp(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'resources/read', [
            'uri' => self::UI_RESOURCE_URI,
        ], 4);

        $contents = $result['result']['contents'][0] ?? [];
        $this->assertSame(self::UI_MIME_TYPE, $contents['mimeType'] ?? null);
        $this->assertStringContainsString('data-mcp-app="maintenance-history"', $contents['text'] ?? '');
        // Resource-content _meta.ui (MCP Apps): drives host CSP / display hints.
        $this->assertTrue($contents['_meta']['ui']['prefersBorder'] ?? null);
    }
}
