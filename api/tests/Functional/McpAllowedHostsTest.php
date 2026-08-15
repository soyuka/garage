<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The MCP SDK ships DNS-rebinding protection whose default allowlist is localhost-only
 * ({@see \Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware}), and
 * symfony/mcp-bundle's controller builds the transport with `middleware: null`, so there is no
 * configuration hook for it. Served on a public hostname the endpoint answers every authenticated
 * request with `403 Forbidden: Invalid Host header.` — an OAuth-looking failure that is not one.
 *
 * The allowlist must therefore be driven by MCP_ALLOWED_HOSTS, and it must still reject a host
 * that is not on it: the middleware is a real protection, not an obstacle to switch off.
 */
final class McpAllowedHostsTest extends AppTestCase
{
    use McpClientTrait;

    private const ALLOWED = 'mcp.example.test'; // must be listed in MCP_ALLOWED_HOSTS (.env.test)
    private const UNKNOWN = 'evil.example';

    /** The host must come from the URI: the test client builds its own Host header and ignores one
     * passed in `headers`, so a header-only variant silently tests nothing (both cases returned 200). */
    private function initializeWithHost(string $host): int
    {
        $client = static::createClient();
        $client->request('POST', 'http://'.$host.'/mcp', [
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

        return $client->getResponse()->getStatusCode();
    }

    public function testConfiguredHostIsAccepted(): void
    {
        $this->assertNotSame(
            403,
            $this->initializeWithHost(self::ALLOWED),
            'a host listed in MCP_ALLOWED_HOSTS must not be rejected by the DNS-rebinding guard',
        );
    }

    public function testUnlistedHostIsStillRejected(): void
    {
        $this->assertSame(
            403,
            $this->initializeWithHost(self::UNKNOWN),
            'the guard must still reject a host that is not on the allowlist',
        );
    }
}
