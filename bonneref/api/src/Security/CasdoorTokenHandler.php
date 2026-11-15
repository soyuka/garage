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
 * (Casdoor signs refresh and ID tokens with the same key, `tokenType` tells them apart); a user token must
 * also belong to the configured organization and carry the owner claim. The audience is
 * NOT checked here but by {@see AudienceTokenHandler}, which wraps this verifier once per firewall with
 * that firewall's accepted audiences (api/docs/AUTH.md at the repo root).
 */
final class CasdoorTokenHandler implements AccessTokenHandlerInterface, AccessTokenVerifier
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
        // Casdoor's `owner` claim: one instance hosts several organizations, and an email is unique only within one.
        private readonly string $organization,
        private readonly string $claim = 'sub',
        private readonly bool $requireVerifiedEmail = true,
        private readonly ?LoggerInterface $logger = null,
        private readonly ClockInterface $clock = new Clock(),
    ) {
        $this->signatureAlgorithm = new AlgorithmManager([
            new RS256(), new RS512(), new ES256(), new ES384(), new ES512(),
        ]);
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $claims = $this->verify($accessToken);

        try {
            if ('' === $this->organization || $this->organization !== ($claims['owner'] ?? null)) {
                throw new BadCredentialsException('The token belongs to another Casdoor organization.');
            }

            $owner = $claims[$this->claim] ?? null;
            if (!\is_string($owner) || '' === $owner || '0' === $owner) {
                throw new MissingClaimException(\sprintf('"%s" claim not found.', $this->claim));
            }

            // An unverified email is a tenancy key anyone can claim by signing up with someone else's address.
            if ('email' === $this->claim && $this->requireVerifiedEmail && true !== ($claims['email_verified'] ?? null)) {
                throw new BadCredentialsException('The email owner claim is not verified.');
            }

            return new UserBadge($owner, null, $claims);
        } catch (\Exception $e) {
            throw $this->refused($e);
        }
    }

    public function verify(#[\SensitiveParameter] string $accessToken): array
    {
        try {
            $keys = $this->discoveryCache->get($this->oidcConfigurationCacheKey, $this->computeDiscoveryKeys(...));
            $claims = $this->loadAndVerifyJws($accessToken, JWKSet::createFromKeyData(['keys' => $keys]));
            $this->verifyClaims($claims);

            return $claims;
        } catch (\Exception $e) {
            throw $this->refused($e);
        }
    }

    private function refused(\Exception $e): BadCredentialsException
    {
        $this->logger?->error('Failed to validate the Casdoor access token.', [
            'error' => $e->getMessage(),
            'exception' => $e::class,
        ]);

        return new BadCredentialsException('Invalid credentials.', (int) $e->getCode(), $e);
    }

    /**
     * @return list<array<array-key, mixed>>
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

        $keys = $this->client->request('GET', $jwksUri)->toArray()['keys'] ?? null;
        if (!\is_array($keys)) {
            throw new \RuntimeException('The "keys" are missing from the JWKS document.');
        }

        $valid = [];
        foreach ($keys as $key) {
            if (!\is_array($key)) {
                throw new \RuntimeException('A JWKS key is not a JSON object.');
            }
            $valid[] = $key;
        }

        return $valid;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function loadAndVerifyJws(#[\SensitiveParameter] string $accessToken, JWKSet $jwkset): array
    {
        $jws = (new JWSSerializerManager([new CompactSerializer()]))->unserialize($accessToken);

        if (!(new JWSVerifier($this->signatureAlgorithm))->verifyWithKeySet($jws, $jwkset, 0)) {
            throw new InvalidSignatureException();
        }

        (new Checker\HeaderCheckerManager(
            [new Checker\AlgorithmChecker($this->signatureAlgorithm->list())],
            [new JWSTokenSupport()],
        ))->check($jws, 0);

        $claims = json_decode($jws->getPayload() ?? '', true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($claims)) {
            throw new \UnexpectedValueException('The token payload is not a JSON object.');
        }

        return $claims;
    }

    /**
     * @param array<array-key, mixed> $claims
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
