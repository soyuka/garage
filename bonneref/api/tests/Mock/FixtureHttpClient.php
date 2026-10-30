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
 * Test double for the upstream HTTP call that is left (the SIV plate lookup): routes requests to fixture
 * files, so tests never hit the network.
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
