<?php

declare(strict_types=1);

namespace App\Security;

use Jose\Component\Checker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWS;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\AccessToken\Oidc\Exception\InvalidSignatureException;
use Symfony\Component\Security\Http\AccessToken\Oidc\Exception\MissingClaimException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies a Casdoor-issued JWT: JWKS signature, iat/nbf/exp, issuer, and that it is an access token
 * (Casdoor signs refresh and ID tokens with the same key, `tokenType` tells them apart). The audience is
 * NOT checked here but by {@see AudienceTokenHandler}, which wraps this verifier once per firewall with
 * that firewall's accepted audiences (docs/AUTH.md).
 */
final readonly class CasdoorTokenHandler implements AccessTokenHandlerInterface
{
    /** Seconds of clock skew tolerated between Casdoor and this host on iat/nbf/exp. */
    private const int ALLOWED_TIME_DRIFT = 30;

    /** A forced JWKS refetch (unknown kid) happens at most once per this many seconds. */
    private const int REFETCH_COOLDOWN = 60;

    private AlgorithmManager $signatureAlgorithm;

    /**
     * @param list<string> $issuers
     */
    public function __construct(
        private HttpClientInterface $client,
        private CacheInterface $discoveryCache,
        private string $oidcConfigurationCacheKey,
        private array $issuers,
        private string $claim = 'sub',
        private bool $requireVerifiedEmail = true,
        private ?LoggerInterface $logger = null,
        private ClockInterface $clock = new Clock(),
    ) {
        $this->signatureAlgorithm = new AlgorithmManager([
            new RS256(), new RS512(), new ES256(), new ES384(), new ES512(),
        ]);
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            $jws = (new JWSSerializerManager([new CompactSerializer()]))->unserialize($accessToken);
            $claims = $this->verifySignature($jws, $this->keySetFor($jws));
            $this->verifyClaims($claims);

            if (empty($claims[$this->claim]) || !\is_string($claims[$this->claim])) {
                throw new MissingClaimException(\sprintf('"%s" claim not found.', $this->claim));
            }

            // An unverified email is a tenancy key anyone can claim by signing up with someone else's address.
            if ('email' === $this->claim && $this->requireVerifiedEmail && true !== ($claims['email_verified'] ?? null)) {
                throw new BadCredentialsException('The email owner claim is not verified.');
            }

            return new UserBadge($claims[$this->claim], null, $claims);
        } catch (\Throwable $e) {
            // A refused token is the caller's problem, not ours: notice, not error. A malformed payload
            // (TypeError, JsonException) is refused the same way instead of escaping as a 500.
            $this->logger?->notice('Refused a Casdoor access token.', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw new BadCredentialsException('Invalid credentials.', $e->getCode(), $e);
        }
    }

    /**
     * The cached JWKS, refetched once when it does not hold the token's `kid`: Casdoor rotated its
     * key and the cache would otherwise refuse every new token for up to an hour. The cooldown keeps
     * a stream of forged kids from turning into a stream of requests to Casdoor.
     */
    private function keySetFor(JWS $jws): JWKSet
    {
        $keySet = JWKSet::createFromKeyData(['keys' => $this->discoveryCache->get($this->oidcConfigurationCacheKey, $this->computeDiscoveryKeys(...))]);

        $signature = $jws->getSignature(0);
        $kid = $signature->hasProtectedHeaderParameter('kid') ? $signature->getProtectedHeaderParameter('kid') : null;
        if (!\is_string($kid) || $keySet->has($kid)) {
            return $keySet;
        }

        $cooldownKey = $this->oidcConfigurationCacheKey.'_refetched';
        $refetched = true;
        $this->discoveryCache->get($cooldownKey, static function (ItemInterface $item) use (&$refetched): bool {
            $item->expiresAfter(self::REFETCH_COOLDOWN);
            $refetched = false;

            return true;
        });
        if ($refetched) {
            return $keySet;
        }

        $this->discoveryCache->delete($this->oidcConfigurationCacheKey);

        return JWKSet::createFromKeyData(['keys' => $this->discoveryCache->get($this->oidcConfigurationCacheKey, $this->computeDiscoveryKeys(...))]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function computeDiscoveryKeys(ItemInterface $item): array
    {
        $config = $this->client
            ->request('GET', rtrim($this->issuers[0], '/').'/.well-known/openid-configuration')
            ->toArray();

        $jwksUri = $config['jwks_uri'] ?? null;
        if (!\is_string($jwksUri) || '' === $jwksUri) {
            throw new \RuntimeException('The "jwks_uri" is missing from the OIDC discovery document.');
        }

        $item->expiresAfter(3600);

        return array_values($this->client->request('GET', $jwksUri)->toArray()['keys']);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifySignature(JWS $jws, JWKSet $jwkset): array
    {
        if (!(new JWSVerifier($this->signatureAlgorithm))->verifyWithKeySet($jws, $jwkset, 0)) {
            throw new InvalidSignatureException();
        }

        (new Checker\HeaderCheckerManager(
            [new Checker\AlgorithmChecker($this->signatureAlgorithm->list())],
            [new JWSTokenSupport()],
        ))->check($jws, 0);

        $claims = json_decode($jws->getPayload() ?? '', true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($claims) || ([] !== $claims && array_is_list($claims))) {
            throw new \UnexpectedValueException('The token payload is not a claims object.');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function verifyClaims(array $claims): void
    {
        (new ClaimCheckerManager([
            new Checker\IssuedAtChecker(clock: $this->clock, allowedTimeDrift: self::ALLOWED_TIME_DRIFT),
            new Checker\NotBeforeChecker(clock: $this->clock, allowedTimeDrift: self::ALLOWED_TIME_DRIFT),
            new Checker\ExpirationTimeChecker(clock: $this->clock, allowedTimeDrift: self::ALLOWED_TIME_DRIFT),
            new Checker\IssuerChecker($this->issuers),
        ]))->check($claims, ['iat', 'exp', 'iss']);

        if ('access-token' !== ($claims['tokenType'] ?? null)) {
            throw new BadCredentialsException('Not an access token.');
        }
    }
}
