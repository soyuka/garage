<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TokenAudienceTest extends AppTestCase
{
    // Must match MPA_SERVICE_CLIENT_ID (.env.test).
    private const string MPA_SERVICE = 'test-mpa-service';
    // The audience mpa's callers' own tokens carry (its Atelier client): accepted on the catalogue until 2026-11-15.
    private const string MPA_CALLER = 'mpa-test-client';

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
        yield 'a forwarded mpa caller' => [[self::MPA_CALLER]];
    }

    public function testMcpRefusesTheMpaService(): void
    {
        $response = $this->initialize(FakeOidcToken::service(self::MPA_SERVICE));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testTheCatalogueAcceptsTheFront(): void
    {
        $this->get('/makes', [FakeOidcToken::ATELIER_CLIENT]);

        $this->assertResponseIsSuccessful();
    }

    #[DataProvider('catalogueRoutesMpaCalls')]
    public function testTheMpaServiceReadsTheCatalogueForTheOwnerItNames(string $path): void
    {
        $response = $this->asMpaService($path, 'driver@example.com');

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogueRoutesMpaCalls(): iterable
    {
        yield 'makes' => ['/makes?carType=PC'];
        yield 'part categories' => ['/cars/34247/part-categories?carType=PC'];
    }

    public function testTheMpaServiceMustNameAnOwner(): void
    {
        $response = $this->asMpaService('/makes', null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testTheMpaServiceStaysOffTheRestOfTheApi(): void
    {
        $response = $this->asMpaService('/agent/turns', 'driver@example.com');

        $this->assertSame(401, $response->getStatusCode());
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
        yield 'a forwarded mpa caller, no passthrough any more' => [[self::MPA_CALLER]];
    }

    public function testTheRestOfTheApiAcceptsTheFront(): void
    {
        $this->get('/agent/turns', [FakeOidcToken::ATELIER_CLIENT]);

        $this->assertResponseIsSuccessful();
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

    private function asMpaService(string $path, ?string $owner): ResponseInterface
    {
        $headers = ['Accept' => 'application/ld+json', 'Authorization' => 'Bearer '.FakeOidcToken::service(self::MPA_SERVICE)];
        if (null !== $owner) {
            $headers['X-Bonneref-Owner'] = $owner;
        }

        return static::createAnonymousClient()->request('GET', $path, ['headers' => $headers]);
    }

    private function initialize(#[\SensitiveParameter] string $token): ResponseInterface
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
