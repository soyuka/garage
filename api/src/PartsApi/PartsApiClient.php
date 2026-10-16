<?php

declare(strict_types=1);

namespace App\PartsApi;

use App\Exception\MissingApiKeyException;
use App\Exception\UpstreamException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * partsapi.ru (TecDoc 2025 Q4) client — hard-cached.
 *
 * Guarantees (carried over from prototype_1, verified against the live API):
 *  - cache key excludes the secret key, so rotating a token never invalidates the cache
 *    and secrets never get persisted; the signature is identical to prototype_1
 *    so already-paid responses can be imported as-is;
 *  - successful responses are cached forever and never re-fetched, except the catalog
 *    snapshots (CATALOG_METHODS) which expire after $catalogTtl;
 *  - auth/network/API errors are never cached.
 */
final class PartsApiClient
{
    /** Model-facing name of the service — never the vendor host or the env var. */
    private const SOURCE = 'the parts catalog';

    private const BASE_URI = 'https://api.partsapi.ru/';

    private const CATALOG_METHODS = ['getArticles', 'getSearchTree'];

    /** Requests actually sent upstream — what the paid quota is charged for. */
    public private(set) int $upstreamCalls = 0;

    /**
     * The same count split by method: the test keys' quota is per method and per day.
     *
     * @var array<string, int>
     */
    public private(set) array $upstreamCallsByMethod = [];

    /**
     * @param array<string, string> $keys one key per partsapi method
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly array $keys,
        private readonly CacheItemPoolInterface $cache,
        private readonly int $catalogTtl = 2592000,
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

        ++$this->upstreamCalls;
        $this->upstreamCallsByMethod[$method] = ($this->upstreamCallsByMethod[$method] ?? 0) + 1;

        try {
            $response = $this->httpClient->request('GET', self::BASE_URI, [
                'query' => ['method' => $method, 'key' => $key] + $params,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw UpstreamException::transient(self::SOURCE, sprintf('partsapi.ru %s: %s', $method, $e->getMessage()), $e);
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

            $reason = sprintf('partsapi.ru %s failed (HTTP %d): %s', $method, $status, $message);

            // A quota/auth rejection is final; a 5xx or a throttle is worth another turn.
            throw $status >= 500 || 429 === $status
                ? UpstreamException::transient(self::SOURCE, $reason)
                : UpstreamException::definitive(self::SOURCE, $reason);
        }

        if (\in_array($method, self::CATALOG_METHODS, true)) {
            $cacheItem->expiresAfter($this->catalogTtl);
        }

        $this->cache->save($cacheItem->set($data));

        return $data;
    }

    /**
     * The cached response, or null when there is none: never spends an upstream call.
     *
     * @param array<string, int|string> $params
     *
     * @return list<array<string, mixed>>|null
     */
    public function peek(string $method, array $params): ?array
    {
        $item = $this->cache->getItem($this->cacheKey($method, $params));

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * The cached responses among several calls of one method, in one cache round trip: never an upstream call.
     *
     * @param array<array-key, array<string, int|string>> $paramSets
     *
     * @return array<array-key, list<array<string, mixed>>> by the key of each hit's params, misses left out
     */
    public function peekMany(string $method, array $paramSets): array
    {
        $keys = array_map(fn (array $params): string => $this->cacheKey($method, $params), $paramSets);
        $byKey = array_flip($keys);
        $hits = [];
        foreach ($this->cache->getItems(array_values($keys)) as $key => $item) {
            if ($item->isHit() && isset($byKey[$key])) {
                $hits[$byKey[$key]] = $item->get();
            }
        }

        return $hits;
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
}
