<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TokenAudienceTest extends AppTestCase
{
    private const string MPA = 'mpa-test-client';

    public function testMcpAcceptsATokenBoundToItsResource(): void
    {
        $this->initialize(FakeOidcToken::for('u1', 'driver@example.com', [FakeOidcToken::MCP_RESOURCE]));

        $this->assertResponseIsSuccessful();
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
        yield 'the bonneref front' => [[FakeOidcToken::ATELIER_CLIENT]];
        yield 'mpa, passthrough is not for /mcp' => [[self::MPA]];
    }

    /**
     * @param list<string> $audience
     */
    #[DataProvider('acceptedOnTheCatalogue')]
    public function testTheCatalogueRoutesMpaCallsAcceptMpaAndTheFront(array $audience): void
    {
        $this->get('/makes', $audience);

        $this->assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function acceptedOnTheCatalogue(): iterable
    {
        yield 'the bonneref front' => [[FakeOidcToken::ATELIER_CLIENT]];
        yield 'mpa forwarding its caller' => [[self::MPA]];
    }

    /**
     * @param list<string> $audience
     */
    #[DataProvider('foreignToTheCatalogue')]
    public function testTheCatalogueRejectsATokenNotIssuedForIt(array $audience): void
    {
        $response = $this->get('/makes', $audience);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('error="invalid_token"', $response->getHeaders(false)['www-authenticate'][0] ?? '');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function foreignToTheCatalogue(): iterable
    {
        yield 'another client' => [['some-other-client']];
        yield 'no audience' => [[]];
    }

    public function testTheRestOfTheApiRefusesMpa(): void
    {
        $this->get('/agent/turns', [FakeOidcToken::ATELIER_CLIENT]);
        $this->assertResponseIsSuccessful();

        $response = $this->get('/agent/turns', [self::MPA]);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testMetadataAdvertisesTheConfiguredResourceForTheRequestedHost(): void
    {
        $response = static::createAnonymousClient()->request('GET', 'https://mcp.example.test/.well-known/oauth-protected-resource');

        $this->assertResponseIsSuccessful();
        $this->assertSame(FakeOidcToken::MCP_RESOURCE, $response->toArray()['resource']);
    }

    /**
     * @param list<string> $audience
     */
    private function get(string $path, array $audience): ResponseInterface
    {
        return static::createAnonymousClient()->request('GET', $path, [
            'headers' => ['Accept' => 'application/ld+json', 'Authorization' => 'Bearer '.FakeOidcToken::for('u1', 'driver@example.com', $audience)],
        ]);
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
