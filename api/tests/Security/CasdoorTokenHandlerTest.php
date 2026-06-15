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

    private JWK $signingKey;

    protected function setUp(): void
    {
        $this->signingKey = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'cert-built-in']);
    }

    public function testValidTokenWithArbitraryAudienceIsAccepted(): void
    {
        // The whole point: Casdoor sets aud = dynamic DCR client_id. The handler must NOT
        // pin the audience, so a token with an unknown client_id as aud still authenticates.
        $token = $this->mintToken([
            'sub' => 'built-in/alice',
            'iss' => self::ISSUER,
            'aud' => ['some-dynamic-dcr-client-id'],
            'iat' => 1000,
            'exp' => 2000,
            'email' => 'alice@example.com',
        ]);

        $badge = $this->handler()->getUserBadgeFrom($token);

        self::assertSame('built-in/alice', $badge->getUserIdentifier());
        self::assertSame('alice@example.com', $badge->getAttributes()['email']);
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

    /**
     * @param array<string, mixed> $claims
     */
    private function mintToken(array $claims, ?JWK $key = null): string
    {
        $key ??= $this->signingKey;
        $builder = new JWSBuilder(new AlgorithmManager([new RS256()]));
        $jws = $builder->create()
            ->withPayload(json_encode($claims, \JSON_THROW_ON_ERROR))
            ->addSignature($key, ['alg' => 'RS256', 'kid' => $key->has('kid') ? $key->get('kid') : 'k'])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    private function handler(int $now = 1500): CasdoorTokenHandler
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
            clock: new MockClock((new \DateTimeImmutable())->setTimestamp($now)),
        );
    }
}
