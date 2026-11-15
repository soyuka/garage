<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\CasdoorTokenHandler;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class CasdoorTokenHandlerTest extends TestCase
{
    private const string ISSUER = 'http://localhost:8000';
    private const string JWKS_URI = 'http://localhost:8000/.well-known/jwks';
    private const string ORGANIZATION = 'bonneref';

    private JWK $signingKey;

    protected function setUp(): void
    {
        $this->signingKey = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'cert-built-in']);
    }

    public function testValidAccessTokenIsAccepted(): void
    {
        $token = $this->mintToken([
            'sub' => 'built-in/alice',
            'iss' => self::ISSUER,
            'aud' => ['https://mcp.example.test/mcp'],
            'iat' => 1000,
            'exp' => 2000,
            'email' => 'alice@example.com',
        ]);

        $badge = $this->handler()->getUserBadgeFrom($token);

        $this->assertSame('built-in/alice', $badge->getUserIdentifier());
        $this->assertSame('alice@example.com', $badge->getAttributes()['email']);
        $this->assertSame(['https://mcp.example.test/mcp'], $badge->getAttributes()['aud']);
    }

    #[DataProvider('notAnAccessToken')]
    public function testOnlyAnAccessTokenIsAccepted(?string $tokenType): void
    {
        $token = $this->mintToken([
            'sub' => 'built-in/alice',
            'iss' => self::ISSUER,
            'aud' => ['x'],
            'iat' => 1000,
            'exp' => 2000,
            'tokenType' => $tokenType,
        ]);

        $this->expectException(BadCredentialsException::class);
        $this->handler()->getUserBadgeFrom($token);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function notAnAccessToken(): iterable
    {
        yield 'refresh token' => ['refresh-token'];
        yield 'id token' => ['id-token'];
        yield 'untyped' => [null];
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->mintToken([
            'sub' => 'built-in/alice',
            'iss' => self::ISSUER,
            'aud' => ['x'],
            'iat' => 1000,
            'exp' => 1200,
        ]);

        $this->expectException(BadCredentialsException::class);
        $this->handler(now: 5000)->getUserBadgeFrom($token);
    }

    public function testWrongIssuerIsRejected(): void
    {
        $token = $this->mintToken([
            'sub' => 'built-in/alice',
            'iss' => 'https://evil.example.com',
            'aud' => ['x'],
            'iat' => 1000,
            'exp' => 2000,
        ]);

        $this->expectException(BadCredentialsException::class);
        $this->handler()->getUserBadgeFrom($token);
    }

    public function testTokenSignedByUnknownKeyIsRejected(): void
    {
        $foreignKey = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig']);
        $token = $this->mintToken([
            'sub' => 'built-in/alice',
            'iss' => self::ISSUER,
            'aud' => ['x'],
            'iat' => 1000,
            'exp' => 2000,
        ], $foreignKey);

        $this->expectException(BadCredentialsException::class);
        $this->handler()->getUserBadgeFrom($token);
    }

    public function testMissingSubClaimIsRejected(): void
    {
        $token = $this->mintToken([
            'iss' => self::ISSUER,
            'aud' => ['x'],
            'iat' => 1000,
            'exp' => 2000,
        ]);

        $this->expectException(BadCredentialsException::class);
        $this->handler()->getUserBadgeFrom($token);
    }

    public function testEmailOwnerClaimRequiresAVerifiedEmail(): void
    {
        $badge = $this->handler(claim: 'email')->getUserBadgeFrom($this->emailToken(['email_verified' => true]));

        $this->assertSame('alice@example.com', $badge->getUserIdentifier());
    }

    /**
     * @param array<string, mixed> $verification
     */
    #[DataProvider('unverifiedEmail')]
    public function testUnverifiedEmailIsRejectedAsOwnerKey(array $verification): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->handler(claim: 'email')->getUserBadgeFrom($this->emailToken($verification));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unverifiedEmail(): iterable
    {
        yield 'false' => [['email_verified' => false]];
        yield 'absent' => [[]];
        yield 'truthy string' => [['email_verified' => 'true']];
    }

    public function testVerificationRequirementCanBeTurnedOff(): void
    {
        $badge = $this->handler(claim: 'email', requireVerifiedEmail: false)
            ->getUserBadgeFrom($this->emailToken(['email_verified' => false]));

        $this->assertSame('alice@example.com', $badge->getUserIdentifier());
    }

    public function testSubOwnerClaimIgnoresEmailVerification(): void
    {
        $badge = $this->handler()->getUserBadgeFrom($this->emailToken(['email_verified' => false]));

        $this->assertSame('built-in/alice', $badge->getUserIdentifier());
    }

    public function testATokenOfTheConfiguredOrganizationIsAccepted(): void
    {
        $badge = $this->handler(claim: 'email')->getUserBadgeFrom($this->emailToken(['email_verified' => true, 'owner' => self::ORGANIZATION]));

        $this->assertSame('alice@example.com', $badge->getUserIdentifier());
    }

    /**
     * Casdoor emails are unique per organization only: the same verified address in another organization of the
     * same instance must not open this tenant.
     *
     * @param array<string, mixed> $organization
     */
    #[DataProvider('foreignOrganization')]
    public function testATokenOfAnotherOrganizationIsRejected(array $organization): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->handler(claim: 'email')->getUserBadgeFrom($this->emailToken(['email_verified' => true] + $organization));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function foreignOrganization(): iterable
    {
        yield 'other organization' => [['owner' => 'built-in']];
        yield 'absent' => [['owner' => null]];
        yield 'not a string' => [['owner' => ['bonneref']]];
    }

    public function testAnEmptyConfiguredOrganizationFailsClosed(): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->handler(claim: 'email', organization: '')->getUserBadgeFrom($this->emailToken(['email_verified' => true, 'owner' => '']));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function emailToken(array $claims): string
    {
        return $this->mintToken($claims + [
            'sub' => 'built-in/alice',
            'iss' => self::ISSUER,
            'aud' => ['x'],
            'iat' => 1000,
            'exp' => 2000,
            'email' => 'alice@example.com',
        ]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function mintToken(array $claims, ?JWK $key = null): string
    {
        $key ??= $this->signingKey;
        $claims += ['tokenType' => 'access-token', 'owner' => self::ORGANIZATION];
        $claims = array_filter($claims, static fn (mixed $v): bool => null !== $v);
        $builder = new JWSBuilder(new AlgorithmManager([new RS256()]));
        $jws = $builder->create()
            ->withPayload(json_encode($claims, \JSON_THROW_ON_ERROR))
            ->addSignature($key, ['alg' => 'RS256', 'kid' => $key->has('kid') ? $key->get('kid') : 'k'])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    private function handler(int $now = 1500, string $claim = 'sub', bool $requireVerifiedEmail = true, string $organization = self::ORGANIZATION): CasdoorTokenHandler
    {
        $client = new MockHttpClient(function (string $method, string $url): JsonMockResponse {
            if (str_contains($url, '.well-known/openid-configuration')) {
                return new JsonMockResponse(['jwks_uri' => self::JWKS_URI]);
            }

            return new JsonMockResponse(['keys' => [$this->signingKey->toPublic()->jsonSerialize()]]);
        });

        return new CasdoorTokenHandler(
            client: $client,
            discoveryCache: new ArrayAdapter(),
            oidcConfigurationCacheKey: 'casdoor_oidc_config',
            issuers: [self::ISSUER],
            organization: $organization,
            claim: $claim,
            requireVerifiedEmail: $requireVerifiedEmail,
            clock: new MockClock((new \DateTimeImmutable())->setTimestamp($now)),
        );
    }
}
