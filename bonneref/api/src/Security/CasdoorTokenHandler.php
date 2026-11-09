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
 * that firewall's accepted audiences (api/docs/AUTH.md at the repo root).
 */
final class CasdoorTokenHandler implements AccessTokenHandlerInterface
{
    private readonly AlgorithmManager $signatureAlgorithm;

    /**
     * @param list<string> $issuers
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly CacheInterface $discoveryCache,
        private readonly string $oidcConfigurationCacheKey,
        private readonly array $issuers,
        private readonly string $claim = 'sub',
        private readonly ?LoggerInterface $logger = null,
        private readonly ClockInterface $clock = new Clock(),
    ) {
        $this->signatureAlgorithm = new AlgorithmManager([
            new RS256(), new RS512(), new ES256(), new ES384(), new ES512(),
        ]);
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            $keys = $this->discoveryCache->get($this->oidcConfigurationCacheKey, $this->computeDiscoveryKeys(...));
            $jwkset = JWKSet::createFromKeyData(['keys' => $keys]);

            $claims = $this->loadAndVerifyJws($accessToken, $jwkset);
            $this->verifyClaims($claims);

            if (empty($claims[$this->claim]) || !\is_string($claims[$this->claim])) {
                throw new MissingClaimException(\sprintf('"%s" claim not found.', $this->claim));
            }

            return new UserBadge($claims[$this->claim], null, $claims);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to validate the Casdoor access token.', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw new BadCredentialsException('Invalid credentials.', $e->getCode(), $e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @internal public so the cache callback can populate it
     */
    public function computeDiscoveryKeys(ItemInterface $item): array
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
    private function loadAndVerifyJws(string $accessToken, JWKSet $jwkset): array
    {
        $jws = (new JWSSerializerManager([new CompactSerializer()]))->unserialize($accessToken);

        if (!(new JWSVerifier($this->signatureAlgorithm))->verifyWithKeySet($jws, $jwkset, 0)) {
            throw new InvalidSignatureException();
        }

        (new Checker\HeaderCheckerManager(
            [new Checker\AlgorithmChecker($this->signatureAlgorithm->list())],
            [new JWSTokenSupport()],
        ))->check($jws, 0);

        return json_decode($jws->getPayload() ?? '', true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function verifyClaims(array $claims): void
    {
        (new ClaimCheckerManager([
            new Checker\IssuedAtChecker(clock: $this->clock, allowedTimeDrift: 0),
            new Checker\NotBeforeChecker(clock: $this->clock, allowedTimeDrift: 0),
            new Checker\ExpirationTimeChecker(clock: $this->clock, allowedTimeDrift: 0),
            new Checker\IssuerChecker($this->issuers),
        ]))->check($claims, ['iat', 'exp', 'iss']);

        if ('access-token' !== ($claims['tokenType'] ?? null)) {
            throw new BadCredentialsException('Not an access token.');
        }
    }
}
