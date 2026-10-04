<?php

declare(strict_types=1);

namespace App\Tests\PartsApi;

use App\PartsApi\PartsApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PartsApiClientTest extends TestCase
{
    public function testNoCallBudget(): void
    {
        $client = $this->client($requests);

        for ($i = 0; $i < 60; ++$i) {
            $this->assertSame([['id' => 1]], $client->fetch('getArticles', ['q' => (string) $i]));
        }

        $this->assertSame(60, $requests);
    }

    public function testCachedResponseDoesNotSpendAnUpstreamCall(): void
    {
        $client = $this->client($requests);

        $client->fetch('getArticles', ['q' => 'a']);
        $client->fetch('getArticles', ['q' => 'a']);

        $this->assertSame(1, $requests);
    }

    private function client(?int &$requests): PartsApiClient
    {
        $requests = 0;
        $httpClient = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode([['id' => 1]]), ['http_code' => 200]);
        });

        return new PartsApiClient($httpClient, ['getArticles' => 'k'], new ArrayAdapter());
    }
}
