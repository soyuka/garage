<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Test double for the Motul upstream, so tests never hit the network. The catalogue and the plate
 * registry are bonneref's, see {@see BonnerefHttpClient}.
 */
final class FixtureHttpClient implements HttpClientInterface
{
    private readonly MockHttpClient $mock;

    public function __construct(private readonly string $fixturesDir)
    {
        $this->mock = new MockHttpClient($this->respond(...));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->mock->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->mock->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return clone $this;
    }

    private function respond(string $method, string $url): MockResponse
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $host = $parts['host'] ?? '';

        if (str_contains($host, 'motul.com')) {
            return $this->motulResponse($parts['path'] ?? '', $query);
        }

        return new MockResponse('unexpected upstream call: '.$url, ['http_code' => 502]);
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

    private static function json(string $body, int $status = 200): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
