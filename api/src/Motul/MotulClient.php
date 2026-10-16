<?php

declare(strict_types=1);

namespace App\Motul;

use App\Exception\UpstreamException;
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
    /** Model-facing name of the service — never the vendor host. */
    private const SOURCE = 'the Motul oil advisor';

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
        private readonly MotulResponseStore $store,
        private readonly MotulRecommendationParser $parser,
        private readonly string $locale = 'fr-FR',
        private readonly string $bu = 'FRA',
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
     * @return list<MotulCategory>
     */
    public function recommendations(string $vehicleId): array
    {
        $url = $this->recommendationsUrl($vehicleId);
        // Cache the raw page (the dehydrated grade format is more stable than its decoded shape) and
        // re-parse on read — parsing is cheap, and the parser can evolve without busting the cache.
        $html = $this->cached('recs.'.sha1($url), fn (): array => ['html' => $this->get($url, [], 'text/html')])['html'];

        return $this->parser->fromNextData(NextData::extract($html));
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
        $cached = $this->store->find($key);
        if (null !== $cached) {
            return $cached;
        }

        $data = $producer();
        $this->store->save($key, $data);

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
            throw UpstreamException::transient(self::SOURCE, sprintf('motul.com: %s', $e->getMessage()), $e);
        }

        if (200 !== $status) {
            $reason = sprintf('motul.com failed (HTTP %d)', $status);

            throw $status >= 500 || 429 === $status
                ? UpstreamException::transient(self::SOURCE, $reason)
                : UpstreamException::definitive(self::SOURCE, $reason);
        }

        return $body;
    }
}
