<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class DenyByDefaultTest extends AppTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function gatedGetProvider(): iterable
    {
        yield 'plate lookup' => ['/vehicles/XC302ZC'];
        yield 'vin lookup' => ['/vins/VF33CKFWB83471925'];
        yield 'part categories' => ['/cars/12345/part-categories?carType=PC'];
        yield 'car identity' => ['/cars/12345/identity?carType=PC'];
        yield 'narrowing' => ['/cars/12345/part-categories/1/narrowed?carType=PC'];
        yield 'articles' => ['/cars/12345/part-categories/1/articles?carType=PC'];
        yield 'makes' => ['/makes'];
        yield 'garage vehicles' => ['/garage/vehicles'];
        yield 'garage vehicle' => ['/garage/vehicles/unknown'];
        yield 'agent journal' => ['/agent/turns'];
        yield 'api docs' => ['/docs.jsonld'];
        yield 'entrypoint' => ['/'];
    }

    #[DataProvider('gatedGetProvider')]
    public function testAnonymousGetIsRejected(string $path): void
    {
        $response = static::createAnonymousClient()->request('GET', $path, ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('Bearer', $response->getHeaders(false)['www-authenticate'][0] ?? '');
    }

    #[DataProvider('gatedGetProvider')]
    public function testAnInvalidTokenIsRejected(string $path): void
    {
        static::createAnonymousClient()->request('GET', $path, ['headers' => ['Accept' => 'application/ld+json', 'Authorization' => 'Bearer not-a-valid-token']]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedPlateLookupIsServed(): void
    {
        static::createAnonymousClient()->request('GET', '/vehicles/XC302ZC', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testAuthenticatedVinLookupIsServed(): void
    {
        static::createAnonymousClient()->request('GET', '/vins/VF33CKFWB83471925', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'vin', 'Search-Input' => 'VF33CKFWB83471925']]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testAuthenticatedCatalogueReadIsNotRejected(): void
    {
        static::createAnonymousClient()->request('GET', '/cars/12345/part-categories?carType=PC', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);

        self::assertNotSame(401, static::getClient()->getResponse()->getStatusCode());
    }

    public function testAuthenticatedGarageReadIsServed(): void
    {
        static::createAnonymousClient()->request('GET', '/garage/vehicles', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testProtectedResourceMetadataStaysPublic(): void
    {
        static::createAnonymousClient()->request('GET', '/.well-known/oauth-protected-resource');

        self::assertResponseStatusCodeSame(200);
    }

    public function testMcpAnonymousChallengePointsAtTheMetadata(): void
    {
        $response = static::createAnonymousClient()->request('POST', '/mcp', ['headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'], 'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']]);

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('resource_metadata=', $response->getHeaders(false)['www-authenticate'][0] ?? '');
    }

    public function testSharedSelectionLinkStaysPublic(): void
    {
        static::createAnonymousClient()->request('GET', '/agent/selections/01JABCDEFGHJKMNPQRSTVWXYZ0');

        self::assertResponseStatusCodeSame(404);
    }
}
