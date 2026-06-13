<?php

declare(strict_types=1);

namespace App\PartsApi;

use App\Exception\BudgetExhaustedException;
use App\Exception\MissingApiKeyException;
use App\Exception\UpstreamException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * partsapi.ru (TecDoc 2025 Q4) client — hard-cached, call-budgeted.
 *
 * Guarantees (carried over from prototype_1, verified against the live API):
 *  - cache key excludes the secret key, so rotating a token never invalidates the cache
 *    and secrets never get persisted; the signature is identical to prototype_1
 *    so already-paid responses can be imported as-is;
 *  - successful responses are cached forever and never re-fetched;
 *  - auth/network/API errors are neither cached nor counted against the budget;
 *  - the budget is enforced per method (each method has its own ~50-call allowance).
 */
final class PartsApiClient
{
    private const BASE_URI = 'https://api.partsapi.ru/';

    /**
     * @param array<string, string> $keys one key per partsapi method
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly array $keys,
        private readonly CacheItemPoolInterface $cache,
        private readonly int $callLimit,
    ) {
    }

    /**
     * @param array<string, int|string> $params
     *
     * @return list<array<string, mixed>>
     */
    public function fetch(string $method, array $params): array
    {
        $cacheItem = $this->cache->getItem($this->cacheKey($method, $params));
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $key = $this->keys[$method] ?? '';
        if ('' === $key) {
            throw MissingApiKeyException::forPartsApiMethod($method);
        }

        if ($this->spent($method) >= $this->callLimit) {
            throw BudgetExhaustedException::forPartsApiMethod($method, $this->callLimit);
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URI, [
                'query' => ['method' => $method, 'key' => $key] + $params,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new UpstreamException(sprintf('partsapi.ru %s: %s', $method, $e->getMessage()), previous: $e);
        }

        $data = json_decode($body, true);

        // Error envelopes: {"code":9000,...} missing params, {"error_code":5002,...} bad key
        // for THIS method, {"error_code":5005,...} bad param type — see docs/PARTSAPI.md.
        $isErrorEnvelope = \is_array($data)
            && (isset($data['error_code']) || isset($data['code']) || (int) ($data['status'] ?? 200) >= 400);

        if (200 !== $status || $isErrorEnvelope || !\is_array($data)) {
            // Cap what we quote: the body is upstream-controlled (HTML error pages…) and the
            // message flows to MCP clients and logs in every environment.
            $message = \is_array($data) ? (string) ($data['message'] ?? '') : mb_substr($body, 0, 200);

            throw new UpstreamException(sprintf('partsapi.ru %s failed (HTTP %d): %s', $method, $status, $message));
        }

        $this->cache->save($cacheItem->set($data));
        $this->bump($method);

        return $data;
    }

    /**
     * Cache key = <method>.sha1(signature), where the signature is the method + params
     * WITHOUT the secret key. The signature is identical to prototype_1, so the sha1 is
     * byte-stable and already-paid responses import 1:1.
     *
     * @param array<string, int|string> $params
     */
    private function cacheKey(string $method, array $params): string
    {
        ksort($params);
        $signature = $method.'?'.http_build_query($params);

        return $method.'.'.sha1($signature);
    }

    private function spent(string $method): int
    {
        $item = $this->cache->getItem('calls.'.$method);

        return $item->isHit() ? (int) $item->get() : 0;
    }

    private function bump(string $method): void
    {
        $item = $this->cache->getItem('calls.'.$method);
        $this->cache->save($item->set(($item->isHit() ? (int) $item->get() : 0) + 1));
    }
}
