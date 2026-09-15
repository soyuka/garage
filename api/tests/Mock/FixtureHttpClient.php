<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Test double for every upstream HTTP call. Routes requests to fixture files copied
 * from prototype_1's real (paid) API responses, so tests never hit the network.
 *
 * Exceptions to "copied from real responses":
 *  - getMakes_PC.json is a 3-entry subset of the live-verified 536-make response
 *    (shape {makeId, makeName} verified 2026-06-12, see docs/PARTSAPI.md);
 *  - carId 500200 is a sentinel triggering the real 5002 "bad key for this method"
 *    error envelope, for the error-is-never-cached-nor-counted tests.
 */
final class FixtureHttpClient implements HttpClientInterface
{
    public const TIMEOUT_PLATE = 'TT000TT';

    private readonly MockHttpClient $mock;

    public function __construct(private readonly string $fixturesDir)
    {
        $this->mock = new MockHttpClient($this->respond(...));
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->mock->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->mock->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return clone $this;
    }

    private function respond(string $method, string $url): MockResponse
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $host = $parts['host'] ?? '';

        if (str_contains($host, 'apiplaqueimmatriculation.com')) {
            return $this->sivResponse((string) ($query['immatriculation'] ?? ''));
        }

        if (str_contains($host, 'partsapi.ru')) {
            return $this->partsApiResponse($query);
        }

        if (str_contains($host, 'motul.com')) {
            return $this->motulResponse($parts['path'] ?? '', $query);
        }

        return new MockResponse('unexpected upstream call: '.$url, ['http_code' => 502]);
    }

    private function sivResponse(string $plate): MockResponse
    {
        // Sentinel: the upstream accepts the connection then never answers — the shape of the
        // 2026-09-14 production failure. Lets a test drive the transient path end to end.
        if (self::TIMEOUT_PLATE === $plate) {
            return new MockResponse(self::idleTimeout());
        }

        $file = $this->fixturesDir.'/siv/'.$plate.'.json';
        if (is_file($file)) {
            return self::json(file_get_contents($file));
        }

        return self::json(json_encode([
            'data' => ['erreur' => 'Véhicule non trouvé'],
            'api-version' => '1.0.0',
            'message' => 'Véhicule non trouvé',
            'code_erreur' => 404,
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function partsApiResponse(array $query): MockResponse
    {
        $method = (string) ($query['method'] ?? '');

        if ('500200' === (string) ($query['carId'] ?? '')) {
            return self::json(json_encode([
                'error_code' => 5002,
                'message' => 'Error when using the authorization key.',
                'status' => 401,
            ], \JSON_THROW_ON_ERROR), 401);
        }

        $file = match ($method) {
            'getMakes' => sprintf('getMakes_%s.json', $query['carType'] ?? 'PC'),
            'getSearchTree' => sprintf('getSearchTree_%s_%s.json', $query['carId'] ?? '', $query['carType'] ?? ''),
            'getArticles' => sprintf('getArticles_%s_%s_%s.json', $query['carId'] ?? '', $query['carType'] ?? '', $query['strId'] ?? ''),
            'GetFillVolumes' => sprintf('GetFillVolumes_%s.json', $query['carId'] ?? ''),
            default => null,
        };

        $path = null !== $file ? $this->fixturesDir.'/partsapi/'.$file : null;
        if (null !== $path && is_file($path)) {
            return self::json(file_get_contents($path));
        }

        // partsapi returns an empty list for unknown ids — mirror that
        return self::json('[]');
    }

    /**
     * Motul Oil Advisor: the gateway `search-vehicle` JSON and the SSR recommendations HTML page
     * (/{locale}/lubricants/recommendations/{id}). Fixtures mirror the live shapes for kType 19107
     * (Mazda 6 1.8, Motul id 9950).
     *
     * @param array<string, mixed> $query
     */
    private function motulResponse(string $path, array $query): MockResponse
    {
        if (str_contains($path, 'search-vehicle')) {
            $file = $this->fixturesDir.'/motul/search-vehicle_mazda6.json';

            return is_file($file) ? self::json(file_get_contents($file)) : self::json('{"vehicles":[]}');
        }

        if (preg_match('#/recommendations/(\d+)#', $path, $m)) {
            $file = $this->fixturesDir.'/motul/recommendations_'.$m[1].'.html';
            if (is_file($file)) {
                return new MockResponse(file_get_contents($file), [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => 'text/html'],
                ]);
            }
        }

        return new MockResponse('not found', ['http_code' => 404]);
    }

    private static function idleTimeout(): \Generator
    {
        throw new TransportException('Idle timeout reached for "https://api.apiplaqueimmatriculation.com/plaque".');

        yield '';
    }

    private static function json(string $body, int $status = 200): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
