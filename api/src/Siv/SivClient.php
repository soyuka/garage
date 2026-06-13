<?php

declare(strict_types=1);

namespace App\Siv;

use App\Exception\MissingApiKeyException;
use App\Exception\UpstreamException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * apiplaqueimmatriculation.com client — French plate → SIV vehicle data.
 *
 * Every successful lookup is cached (keyed by the normalized plate, value = the full
 * envelope, same shape as prototype_1's <PLATE>.json) and never re-paid. Failed
 * lookups are never cached.
 */
final class SivClient
{
    private const BASE_URI = 'https://api.apiplaqueimmatriculation.com/plaque';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $token,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public static function normalizePlate(string $raw): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    /**
     * @return array<string, mixed>|null the SIV `data` payload, or null when the plate is unknown
     */
    public function lookup(string $plate): ?array
    {
        $plate = self::normalizePlate($plate);
        if ('' === $plate) {
            return null;
        }

        $item = $this->cache->getItem($plate);
        if ($item->isHit()) {
            return $item->get()['data'] ?? null;
        }

        if ('' === $this->token) {
            throw MissingApiKeyException::forSiv();
        }

        try {
            $response = $this->httpClient->request('POST', self::BASE_URI, [
                'query' => ['immatriculation' => $plate, 'token' => $this->token, 'pays' => 'FR'],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new UpstreamException('apiplaqueimmatriculation.com: '.$e->getMessage(), previous: $e);
        }

        $envelope = json_decode($body, true);
        if (!\is_array($envelope) || 200 !== $status) {
            throw new UpstreamException(sprintf('apiplaqueimmatriculation.com bad response (HTTP %d)', $status));
        }

        $errorCode = (int) ($envelope['code_erreur'] ?? 200);
        $dataError = (string) ($envelope['data']['erreur'] ?? '');

        if (200 === $errorCode && '' === $dataError && \is_array($envelope['data'] ?? null)) {
            $this->cache->save($item->set($envelope));

            return $envelope['data'];
        }

        // Unknown plate: the upstream signals it via data.erreur and/or a 404 error code.
        if ('' !== $dataError || 404 === $errorCode) {
            return null;
        }

        throw new UpstreamException(sprintf('apiplaqueimmatriculation.com error %d: %s', $errorCode, $envelope['message'] ?? ''));
    }
}
