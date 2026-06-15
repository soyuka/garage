<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The /mcp endpoint is protected by the OIDC firewall (Zitadel in production). These
 * tests pin the OAuth bootstrap surface an MCP client relies on (RFC 9728): an
 * unauthenticated call is challenged with a pointer to the protected-resource
 * metadata document, and that document is reachable without a token.
 */
final class McpOAuthTest extends AppTestCase
{
    public function testUnauthenticatedMcpCallIsChallenged(): void
    {
        $client = static::createClient();

        $response = $client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ],
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

        $this->assertSame(401, $response->getStatusCode());
        $challenge = $response->getHeaders(false)['www-authenticate'][0] ?? '';
        $this->assertStringContainsString('Bearer', $challenge);
        $this->assertStringContainsString('resource_metadata="', $challenge);
        $this->assertStringContainsString('/.well-known/oauth-protected-resource', $challenge);
    }

    public function testProtectedResourceMetadataIsPublic(): void
    {
        $client = static::createClient();

        $response = $client->request('GET', '/.well-known/oauth-protected-resource');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();
        $this->assertStringEndsWith('/mcp', $data['resource']);
        $this->assertNotEmpty($data['authorization_servers']);
    }

    public function testAuthenticatedMcpCallSucceeds(): void
    {
        $client = static::createClient();

        $response = $client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'Authorization' => 'Bearer '.FakeOidcToken::for('zitadel-user-1', 'driver@example.com'),
            ],
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
        $this->assertNotSame('', $response->getHeaders()['mcp-session-id'][0] ?? '');
    }
}
