<?php

declare(strict_types=1);

namespace App\Motul;

use App\Exception\UpstreamException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Motul "Oil Advisor" reader — manufacturer oil-grade recommendations by vehicle.
 *
 * Two upstreams, both cached forever (a vehicle's recommendation is stable): a clean JSON
 * `search-vehicle` API, and the SSR recommendations page whose grades live in `__NEXT_DATA__`
 * ({@see NextData}, {@see MotulRecommendationParser}). Browser-ish headers are required or the
 * gateway/WAF rejects the request. Read-only and rate-respectful: cache hits never re-fetch.
 */
final class MotulClient
{
    private const SEARCH_URI = 'https://gateway-apim.motul.com/oil-advisor/search-vehicle';
    private const PAGE_BASE = 'https://www.motul.com';
    private const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0',
        'Accept' => 'application/json, text/plain, */*',
        'Referer' => 'https://www.motul.com/',
        'Origin' => 'https://www.motul.com',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly MotulRecommendationParser $parser,
        private readonly string $locale = 'en-US',
        private readonly string $bu = 'USA',
    ) {
    }

    /**
     * @return list<MotulVehicle>
     */
    public function searchVehicle(string $search): array
    {
        $data = $this->cached('search.'.sha1($this->locale.'|'.$this->bu.'|'.$search), function () use ($search): array {
            $body = $this->get(self::SEARCH_URI, ['search' => $search, 'locale' => $this->locale, 'BU' => $this->bu]);
            $json = json_decode($body, true);

            return \is_array($json['vehicles'] ?? null) ? $json['vehicles'] : [];
        });

        return array_map(MotulVehicle::fromArray(...), $data);
    }

    /**
     * @return list<MotulOilRecommendation>
     */
    public function recommendations(string $vehicleId): array
    {
        $url = sprintf('%s/%s/lubricants/recommendations/%s', self::PAGE_BASE, $this->locale, rawurlencode($vehicleId));
        $rows = $this->cached('recs.'.sha1($url), function () use ($url): array {
            $html = $this->get($url, [], 'text/html');
            $recs = $this->parser->fromNextData(NextData::extract($html));

            return array_map(static fn (MotulOilRecommendation $r): array => (array) $r, $recs);
        });

        return array_map(static fn (array $r): MotulOilRecommendation => new MotulOilRecommendation(...$r), $rows);
    }

    public function recommendationsUrl(string $vehicleId): string
    {
        return sprintf('%s/%s/lubricants/recommendations/%s', self::PAGE_BASE, $this->locale, rawurlencode($vehicleId));
    }

    /**
     * @param callable():array<mixed> $producer
     *
     * @return array<mixed>
     */
    private function cached(string $key, callable $producer): array
    {
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            return $item->get();
        }

        $data = $producer();
        $this->cache->save($item->set($data));

        return $data;
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $url, array $query = [], string $accept = 'application/json, text/plain, */*'): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => $query,
                'headers' => ['Accept' => $accept] + self::HEADERS,
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new UpstreamException(sprintf('motul.com: %s', $e->getMessage()), previous: $e);
        }

        if (200 !== $status) {
            throw new UpstreamException(sprintf('motul.com failed (HTTP %d)', $status));
        }

        return $body;
    }
}
