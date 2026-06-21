<?php

declare(strict_types=1);

namespace App\Tests\Unit\Siv;

use App\Exception\UpstreamException;
use App\Siv\SivClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SivClientTest extends TestCase
{
    public function testKnownPlateIsCachedAndNotRefetched(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(json_encode(['code_erreur' => 200, 'data' => ['erreur' => '', 'immat' => 'AA123BB', 'marque' => 'PEUGEOT']]));
        });
        $client = new SivClient($http, 'token', new ArrayAdapter());

        $this->assertSame('PEUGEOT', $client->lookup('AA-123-BB')['marque']);
        $client->lookup('AA-123-BB');

        $this->assertSame(1, $calls, 'a successful lookup must be served from cache the second time');
    }

    public function testUnknownPlateIsNegativelyCachedAndNotRefetched(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            // The upstream signals an unknown plate via data.erreur (here also a 404 error code).
            return new MockResponse(json_encode(['code_erreur' => 404, 'data' => ['erreur' => 'immatriculation inconnue']]));
        });
        $client = new SivClient($http, 'token', new ArrayAdapter());

        $this->assertNull($client->lookup('ZZ999ZZ'));
        $this->assertNull($client->lookup('ZZ999ZZ'));

        $this->assertSame(1, $calls, 'an unknown plate must not re-hit the paid SIV API on repeat lookups');
    }

    public function testTransientErrorIsNotCached(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('gateway down', ['http_code' => 503]);
        });
        $client = new SivClient($http, 'token', new ArrayAdapter());

        foreach (range(1, 2) as $_) {
            try {
                $client->lookup('AA123BB');
                $this->fail('expected UpstreamException');
            } catch (UpstreamException) {
            }
        }

        $this->assertSame(2, $calls, 'a transient failure must stay uncached so a later retry can succeed');
    }
}
