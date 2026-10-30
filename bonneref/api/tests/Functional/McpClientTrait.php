<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;

/**
 * JSON-RPC over Streamable HTTP plumbing for the /mcp endpoint, shared by the
 * MCP functional tests (initialize handshake, tools/list, tools/call).
 */
trait McpClientTrait
{
    private const MCP_HEADERS = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
    ];

    /**
     * The /mcp firewall requires a bearer token; in tests it is decoded by
     * {@see \App\Tests\Security\FakeOidcTokenHandler} rather than verified against Zitadel.
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function mcpHeaders(array $extra = []): array
    {
        return self::MCP_HEADERS + [
            // The owner IS the email claim (55bf873), so this must be TEST_OWNER or an MCP write
            // lands on a different tenant than the seeds.
            'Authorization' => 'Bearer '.FakeOidcToken::for('mcp-user', AppTestCase::TEST_OWNER),
        ] + $extra;
    }

    private function initialize(Client $client): string
    {
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

        $this->assertResponseIsSuccessful();
        $sessionId = $response->getHeaders()['mcp-session-id'][0] ?? '';
        $this->assertNotSame('', $sessionId, 'initialize must return an Mcp-Session-Id header');

        $client->request('POST', '/mcp', [
            'headers' => $this->mcpHeaders(['Mcp-Session-Id' => $sessionId]),
            'json' => ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ]);

        return $sessionId;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(Client $client, string $sessionId, string $method, array $params, int $id): array
    {
        $response = $client->request('POST', '/mcp', [
            'headers' => $this->mcpHeaders(['Mcp-Session-Id' => $sessionId]),
            'json' => ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ?: new \stdClass()],
        ]);

        $this->assertResponseIsSuccessful();

        $body = $response->getContent();
        $json = $this->extractJson($body);
        $this->assertArrayNotHasKey('error', $json, 'JSON-RPC error: '.$body);

        return $json;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{code: int, message: string}
     */
    private function callExpectingError(Client $client, string $sessionId, string $method, array $params, int $id): array
    {
        $response = $client->request('POST', '/mcp', [
            'headers' => $this->mcpHeaders(['Mcp-Session-Id' => $sessionId]),
            'json' => ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params],
        ]);

        $json = $this->extractJson($response->getContent(false));
        $this->assertArrayHasKey('error', $json, 'Expected a JSON-RPC error, got: '.json_encode($json));

        return $json['error'];
    }

    /**
     * Streamable HTTP responses may come back as plain JSON or as an SSE stream.
     *
     * @return array<string, mixed>
     */
    private function extractJson(string $body): array
    {
        if (str_starts_with(ltrim($body), '{')) {
            return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        }

        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data:')) {
                return json_decode(trim(substr($line, 5)), true, 512, \JSON_THROW_ON_ERROR);
            }
        }

        $this->fail('No JSON payload found in MCP response: '.$body);
    }
}
