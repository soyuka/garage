<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TokenAudienceTest extends AppTestCase
{
    public function testMcpAcceptsATokenBoundToItsResource(): void
    {
        $response = $this->initialize(FakeOidcToken::for('u1', 'driver@example.com', [FakeOidcToken::MCP_RESOURCE]));

        $this->assertResponseIsSuccessful();
        $this->assertNotSame('', $response->getHeaders()['mcp-session-id'][0] ?? '');
    }

    /**
     * @param list<string> $audience
     */
    #[DataProvider('foreignToMcp')]
    public function testMcpRejectsATokenNotIssuedForIt(array $audience): void
    {
        $response = $this->initialize(FakeOidcToken::for('u1', 'driver@example.com', $audience));

        $this->assertSame(401, $response->getStatusCode());
        $challenge = $response->getHeaders(false)['www-authenticate'][0] ?? '';
        $this->assertStringContainsString('error="invalid_token"', $challenge);
        $this->assertStringContainsString('resource_metadata="', $challenge);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function foreignToMcp(): iterable
    {
        yield 'another server' => [['https://evil.example/mcp']];
        yield 'no audience' => [[]];
        yield 'the Atelier client' => [[FakeOidcToken::ATELIER_CLIENT]];
    }

    public function testRestAcceptsTheAtelierClient(): void
    {
        static::createAnonymousClient()->request('GET', '/agent/link', [
            'headers' => ['Authorization' => 'Bearer '.FakeOidcToken::for('u1', 'driver@example.com', [FakeOidcToken::ATELIER_CLIENT])],
        ]);

        $this->assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $audience
     */
    #[DataProvider('foreignToRest')]
    public function testRestRejectsATokenNotIssuedForIt(array $audience): void
    {
        $response = static::createAnonymousClient()->request('GET', '/agent/link', [
            'headers' => ['Authorization' => 'Bearer '.FakeOidcToken::for('u1', 'driver@example.com', $audience)],
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('error="invalid_token"', $response->getHeaders(false)['www-authenticate'][0] ?? '');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function foreignToRest(): iterable
    {
        yield 'another client' => [['some-other-client']];
        yield 'no audience' => [[]];
        yield 'the MCP resource' => [[FakeOidcToken::MCP_RESOURCE]];
    }

    public function testMetadataAdvertisesTheConfiguredResourceForTheRequestedHost(): void
    {
        $response = static::createAnonymousClient()->request('GET', 'https://mcp.example.test/.well-known/oauth-protected-resource');

        $this->assertResponseIsSuccessful();
        $this->assertSame(FakeOidcToken::MCP_RESOURCE, $response->toArray()['resource']);
    }

    private function initialize(string $token): ResponseInterface
    {
        return static::createAnonymousClient()->request('POST', 'https://mcp.example.test/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'Authorization' => 'Bearer '.$token,
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
    }
}
