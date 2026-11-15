<?php

declare(strict_types=1);

namespace App\Bonneref;

use App\Exception\NotConfiguredException;
use App\Exception\UpstreamException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * mpa's own access token for bonneref: OAuth client credentials of the Casdoor application
 * `mpa-service`, minted at the issuer's discovered token endpoint and cached until shortly before it
 * expires. It replaces forwarding the caller's bearer (MCP forbids token passthrough, api/docs/AUTH.md);
 * the end user travels beside it as the `X-Bonneref-Owner` header {@see BonnerefClient} adds.
 */
final readonly class BonnerefServiceToken
{
    /** Seconds before `expires_in` at which a cached token is no longer handed out. */
    public const int EXPIRY_MARGIN = 300;

    private const string SOURCE = 'the bonneref service credential';

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        private string $issuer,
        private string $clientId,
        #[\SensitiveParameter]
        private string $clientSecret,
    ) {
    }

    public function get(): string
    {
        if ('' === $this->clientId || '' === $this->clientSecret) {
            throw NotConfiguredException::bonnerefServiceCredential();
        }

        return $this->cache->get($this->cacheKey(), $this->mint(...));
    }

    /** bonneref refused the cached token (revoked, key rotated): the next call mints a fresh one. */
    public function forget(): void
    {
        $this->cache->delete($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'bonneref_service_token_'.hash('xxh128', $this->issuer."\0".$this->clientId);
    }

    private function mint(ItemInterface $item): string
    {
        try {
            $response = $this->http->request('POST', $this->tokenEndpoint(), ['body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                // The body is not quoted: Casdoor's error description may echo the submitted credential.
                $message = \sprintf('Casdoor refused the client credentials grant with %d.', $status);

                throw $status >= 500 || 429 === $status ? UpstreamException::transient(self::SOURCE, $message) : UpstreamException::definitive(self::SOURCE, $message);
            }
            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw UpstreamException::transient(self::SOURCE, 'Casdoor token endpoint: '.$e->getMessage(), $e);
        }

        $token = $data['access_token'] ?? null;
        if (!\is_string($token) || '' === $token) {
            throw UpstreamException::definitive(self::SOURCE, 'Casdoor answered the client credentials grant without an access token.');
        }

        $expiresIn = $data['expires_in'] ?? null;
        $item->expiresAfter(\is_int($expiresIn) && $expiresIn > 0 ? max(1, $expiresIn - min(self::EXPIRY_MARGIN, intdiv($expiresIn, 2))) : 60);

        return $token;
    }

    private function tokenEndpoint(): string
    {
        $endpoint = $this->http->request('GET', rtrim($this->issuer, '/').'/.well-known/openid-configuration')->toArray()['token_endpoint'] ?? null;
        if (!\is_string($endpoint) || '' === $endpoint) {
            throw UpstreamException::definitive(self::SOURCE, 'The "token_endpoint" is missing from the OIDC discovery document.');
        }

        return $endpoint;
    }
}
