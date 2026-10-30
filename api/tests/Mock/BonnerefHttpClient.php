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
 * The bonneref transport in the test env: routes by path, answers from JSON-LD fixtures captured from
 * the catalog this API used to serve itself, and records every outgoing request so a test can assert
 * what was forwarded (the bearer above all). The most specific route wins: one whose query parameters
 * all appear on the request, with the most of them.
 *
 * Routes and requests are static: the kernel reboots between two requests of a test, and the double must
 * outlive that. {@see reset()} runs in every test's setUp.
 */
final class BonnerefHttpClient implements HttpClientInterface
{
    private const string FIXTURES = __DIR__.'/../Fixtures/bonneref/';

    /** @var list<array{method: string, url: string, path: string, query: array<string, string>, headers: array<string, string>}> */
    public static array $requests = [];

    /** @var list<array{path: string, query: array<string, string>, body: string|\Throwable, status: int}> */
    private static array $routes = [];

    private readonly MockHttpClient $mock;

    public static function reset(): void
    {
        self::$requests = [];
        self::$routes = [];
    }

    public function __construct()
    {
        $this->mock = new MockHttpClient($this->respond(...), 'https://bonneref.test');
    }

    /**
     * @param array<string, string> $query
     */
    public function route(string $path, string|\Throwable $body, int $status = 200, array $query = []): self
    {
        self::$routes[] = ['path' => $path, 'query' => $query, 'body' => $body, 'status' => $status];

        return $this;
    }

    /**
     * @param array<string, string> $query
     */
    public function fixture(string $path, string $name, array $query = [], int $status = 200): self
    {
        return $this->route($path, (string) file_get_contents(self::FIXTURES.$name.'.json'), $status, $query);
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
        return $this;
    }

    public function lastRequest(): ?array
    {
        return [] === self::$requests ? null : self::$requests[array_key_last(self::$requests)];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $path = $parts['path'] ?? '/';

        $headers = [];
        foreach ($options['normalized_headers'] ?? [] as $name => $lines) {
            $headers[$name] = preg_replace('/^[^:]+:\s*/', '', (string) $lines[0]);
        }

        self::$requests[] = ['method' => $method, 'url' => $url, 'path' => $path, 'query' => $query, 'headers' => $headers];

        $best = null;
        foreach (self::$routes as $route) {
            if ($route['path'] !== $path) {
                continue;
            }
            foreach ($route['query'] as $key => $value) {
                if (($query[$key] ?? null) !== $value) {
                    continue 2;
                }
            }
            if (null === $best || \count($route['query']) >= \count($best['query'])) {
                $best = $route;
            }
        }

        if (null === $best) {
            return new MockResponse('{"title":"no route"}', ['http_code' => 404]);
        }

        if ($best['body'] instanceof \Throwable) {
            throw $best['body'] instanceof TransportException ? $best['body'] : new TransportException($best['body']->getMessage());
        }

        return new MockResponse($best['body'], [
            'http_code' => $best['status'],
            'response_headers' => ['content-type' => 'application/ld+json'],
        ]);
    }
}
