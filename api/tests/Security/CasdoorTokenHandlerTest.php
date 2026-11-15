<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\CasdoorTokenHandler;
use App\Tests\Mock\RecordingLogger;
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

    /** @var list<JWK> what the JWKS endpoint serves right now */
    private array $served;

    private int $jwksFetches = 0;

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->signingKey = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'cert-built-in']);
        $this->served = [$this->signingKey];
        $this->cache = new ArrayAdapter();
    }

    public function testANonObjectPayloadIsRejectedNotA500(): void
    {
        $jws = (new JWSBuilder(new AlgorithmManager([new RS256()])))->create()
            ->withPayload('42')
            ->addSignature($this->signingKey, ['alg' => 'RS256', 'kid' => 'cert-built-in'])
            ->build();

        $this->expectException(BadCredentialsException::class);
        $this->handler()->getUserBadgeFrom((new CompactSerializer())->serialize($jws, 0));
    }

    public function testAnInvalidTokenIsLoggedBelowError(): void
    {
        $logger = new RecordingLogger();

        try {
            $this->handler(logger: $logger)->getUserBadgeFrom('not.a.jwt');
            $this->fail('an invalid token must be refused');
            // @mago-expect lint:no-empty-catch-clause the refusal is expected; the assertions below inspect the logger and the JWKS fetches
        } catch (BadCredentialsException) {
        }

        $this->assertNotEmpty($logger->records);
        $this->assertSame([], array_filter($logger->records, static fn (array $r): bool => \in_array($r['level'], ['error', 'critical', 'alert', 'emergency'], true)));
    }

    public function testASmallClockSkewIsTolerated(): void
    {
        $token = $this->mintToken(['sub' => 'built-in/alice', 'iss' => self::ISSUER, 'aud' => ['x'], 'iat' => 1520, 'nbf' => 1520, 'exp' => 2000]);

        $this->assertSame('built-in/alice', $this->handler(now: 1500)->getUserBadgeFrom($token)->getUserIdentifier());
    }

    public function testARotatedKeyIsPickedUpWithoutWaitingForTheCache(): void
    {
        $handler = $this->handler();
        $handler->getUserBadgeFrom($this->mintToken(['sub' => 'a', 'iss' => self::ISSUER, 'aud' => ['x'], 'iat' => 1000, 'exp' => 2000]));
        $this->assertSame(1, $this->jwksFetches);

        $rotated = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'cert-rotated']);
        $this->served = [$rotated];

        $badge = $handler->getUserBadgeFrom($this->mintToken(['sub' => 'b', 'iss' => self::ISSUER, 'aud' => ['x'], 'iat' => 1000, 'exp' => 2000], $rotated));

        $this->assertSame('b', $badge->getUserIdentifier());
        $this->assertSame(2, $this->jwksFetches);
    }

    public function testAnUnknownKidRefetchesOnlyOnce(): void
    {
        $handler = $this->handler();
        $handler->getUserBadgeFrom($this->mintToken(['sub' => 'a', 'iss' => self::ISSUER, 'aud' => ['x'], 'iat' => 1000, 'exp' => 2000]));
        $stranger = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'cert-stranger']);

        try {
            $handler->getUserBadgeFrom($this->mintToken(['sub' => 'b', 'iss' => self::ISSUER, 'aud' => ['x'], 'iat' => 1000, 'exp' => 2000], $stranger));
            $this->fail('a key the issuer does not publish must be refused');
            // @mago-expect lint:no-empty-catch-clause the refusal is expected; the assertions below inspect the logger and the JWKS fetches
        } catch (BadCredentialsException) {
        }

        $this->assertSame(2, $this->jwksFetches);
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

    /**
     * Casdoor emails are unique per organization, not per instance: a second org on the same issuer could
     * hold a verified account with the victim's address, and the email is the tenancy key.
     */
    #[DataProvider('foreignOrganizations')]
    public function testATokenFromAnotherOrganizationIsRejected(?string $organization): void
    {
        $claims = ['owner' => $organization, 'email_verified' => true];
        if (null === $organization) {
            $claims = ['email_verified' => true];
        }
        $token = $this->mintToken($claims + [
            'sub' => 'other/alice',
            'iss' => self::ISSUER,
            'aud' => ['x'],
            'iat' => 1000,
            'exp' => 2000,
            'email' => 'alice@example.com',
        ], organization: false);

        $this->expectException(BadCredentialsException::class);
        $this->handler(claim: 'email')->getUserBadgeFrom($token);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function foreignOrganizations(): iterable
    {
        yield 'another organization' => ['built-in'];
        yield 'no organization' => [null];
        yield 'empty organization' => [''];
    }

    public function testAnUnconfiguredOrganizationFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->handler(organization: '');
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
    private function mintToken(array $claims, ?JWK $key = null, bool $organization = true): string
    {
        $key ??= $this->signingKey;
        $claims += ['tokenType' => 'access-token'];
        if ($organization) {
            $claims += ['owner' => self::ORGANIZATION];
        }
        $builder = new JWSBuilder(new AlgorithmManager([new RS256()]));
        $jws = $builder->create()
            ->withPayload(json_encode($claims, \JSON_THROW_ON_ERROR))
            ->addSignature($key, ['alg' => 'RS256', 'kid' => $key->has('kid') ? $key->get('kid') : 'k'])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    private function handler(int $now = 1500, string $claim = 'sub', bool $requireVerifiedEmail = true, ?RecordingLogger $logger = null, string $organization = self::ORGANIZATION): CasdoorTokenHandler
    {
        $client = new MockHttpClient(function (string $method, string $url): JsonMockResponse {
            if (str_contains($url, '.well-known/openid-configuration')) {
                return new JsonMockResponse(['jwks_uri' => self::JWKS_URI]);
            }
            ++$this->jwksFetches;

            return new JsonMockResponse(['keys' => array_map(static fn (JWK $key): array => $key->toPublic()->jsonSerialize(), $this->served)]);
        });

        return new CasdoorTokenHandler(
            client: $client,
            discoveryCache: $this->cache,
            oidcConfigurationCacheKey: 'casdoor_oidc_config',
            issuers: [self::ISSUER],
            organization: $organization,
            claim: $claim,
            requireVerifiedEmail: $requireVerifiedEmail,
            logger: $logger,
            clock: new MockClock((new \DateTimeImmutable())->setTimestamp($now)),
        );
    }
}
