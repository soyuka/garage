<?php

declare(strict_types=1);

namespace App\Tests\Unit\Siv;

use App\Exception\InvalidPlateException;
use App\Exception\MissingApiKeyException;
use App\Exception\UpstreamException;
use App\Siv\SivClient;
use App\Tests\Support\InMemorySivLookupStore;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\UnlimitedQuota;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SivClientTest extends TestCase
{
    public function testAMissingTokenNamesItsEnvVarInTheLogOnly(): void
    {
        $logger = new RecordingLogger();
        $client = new SivClient(new MockHttpClient(), '', new InMemorySivLookupStore(), UnlimitedQuota::create(), logger: $logger);

        try {
            $client->lookup('AB-123-CD');
            $this->fail('A missing token must throw.');
        } catch (MissingApiKeyException $e) {
            $this->assertStringNotContainsString('API_PLAQUE_TOKEN', $e->getMessage(), 'the message can reach a client');
        }

        $this->assertStringContainsString('API_PLAQUE_TOKEN', $logger->dump());
    }

    public function testKnownPlateIsCachedAndNotRefetched(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(json_encode(['code_erreur' => 200, 'data' => ['erreur' => '', 'immat' => 'AA123BB', 'marque' => 'PEUGEOT']]));
        });
        $client = new SivClient($http, 'token', new InMemorySivLookupStore(), UnlimitedQuota::create());

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
        $client = new SivClient($http, 'token', new InMemorySivLookupStore(), UnlimitedQuota::create());

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
        $client = new SivClient($http, 'token', new InMemorySivLookupStore(), UnlimitedQuota::create());

        foreach (range(1, 2) as $_) {
            try {
                $client->lookup('AA123BB');
                $this->fail('expected UpstreamException');
            } catch (UpstreamException) {
            }
        }

        $this->assertSame(2, $calls, 'a transient failure must stay uncached so a later retry can succeed');
    }

    /**
     * The production failure of 2026-09-14: the upstream accepted the connection then sent
     * nothing for 15 s. Symfony's RetryableHttpClient provably does NOT retry a bare idle
     * timeout (it yields the timeout chunk before consulting the retry strategy,
     * RetryableHttpClient.php:88 — measured: 1 TCP connection with max_retries=2), so the
     * retry has to live here.
     */
    public function testIdleTimeoutIsRetriedAndThenSucceeds(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            if (++$calls < 3) {
                return new MockResponse(self::idleTimeout());
            }

            return new MockResponse(json_encode(['code_erreur' => 200, 'data' => ['erreur' => '', 'marque' => 'PEUGEOT']]));
        });
        $client = new SivClient($http, 'token', new InMemorySivLookupStore(), UnlimitedQuota::create(), retryDelayMs: 0);

        $this->assertSame('PEUGEOT', $client->lookup('AA-123-BB')['marque']);
        $this->assertSame(3, $calls, 'an idle timeout must be retried, not surfaced on the first miss');
    }

    public function testRetriesAreBoundedAndTheFailureIsMarkedTransient(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::idleTimeout());
        });
        $client = new SivClient($http, 'token', new InMemorySivLookupStore(), UnlimitedQuota::create(), retryDelayMs: 0);

        try {
            $client->lookup('AA-123-BB');
            $this->fail('expected UpstreamException');
        } catch (UpstreamException $e) {
            $this->assertTrue($e->transient, 'a timeout is transient — the model must be told it can retry');
        }

        $this->assertSame(3, $calls, 'one initial attempt plus two retries, then give up');
    }

    /**
     * A 5xx is retried by the scoped RetryableHttpClient (framework.http_client.retry_failed).
     * Retrying it HERE too would multiply the attempts (3 x 3 = 9 requests to a paid API).
     */
    public function testServerErrorIsLeftToTheScopedClientAndNotRetriedHere(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('gateway down', ['http_code' => 503]);
        });
        $client = new SivClient($http, 'token', new InMemorySivLookupStore(), UnlimitedQuota::create(), retryDelayMs: 0);

        try {
            $client->lookup('AA123BB');
            $this->fail('expected UpstreamException');
        } catch (UpstreamException $e) {
            $this->assertTrue($e->transient, 'a 503 is transient');
        }

        $this->assertSame(1, $calls, 'the transport-level retry belongs to the scoped http client, not to this loop');
    }

    public function testUnknownPlateIsDefinitiveAndNeverRetried(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(json_encode(['code_erreur' => 404, 'data' => ['erreur' => 'immatriculation inconnue']]));
        });
        $client = new SivClient($http, 'token', new InMemorySivLookupStore(), UnlimitedQuota::create(), retryDelayMs: 0);

        $this->assertNull($client->lookup('ZZ999ZZ'));
        $this->assertSame(1, $calls);
    }

    /**
     * A malformed plate rejected by the upstream is NOT worth a retry nor a second billing.
     */
    public function testUpstreamRejectionIsDefinitive(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode(['code_erreur' => 400, 'message' => 'plaque invalide', 'data' => []])));
        $client = new SivClient($http, 'token', new InMemorySivLookupStore(), UnlimitedQuota::create(), retryDelayMs: 0);

        try {
            $client->lookup('AA123BB');
            $this->fail('expected UpstreamException');
        } catch (UpstreamException $e) {
            $this->assertFalse($e->transient, 'an upstream rejection is definitive — retrying cannot help');
        }
    }

    #[DataProvider('notFrenchPlates')]
    public function testANonFrenchPlateNeverReachesTheUpstream(string $plate): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            $this->fail('a malformed plate must not be billed');
        });
        $client = new SivClient($http, 'token', new InMemorySivLookupStore(), UnlimitedQuota::create());

        $this->expectException(InvalidPlateException::class);
        $client->lookup($plate);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFrenchPlates(): iterable
    {
        yield 'empty' => [''];
        yield 'letters only' => ['ABCDEFGH'];
        yield 'SIV with an O' => ['AO-123-BC'];
        yield 'too many digits' => ['AB-1234-CD'];
        yield 'FNI without département' => ['1234AB'];
    }

    #[DataProvider('frenchPlates')]
    public function testSivAndFniPlatesAreFrench(string $plate): void
    {
        $this->assertTrue(SivClient::isFrenchPlate(SivClient::normalizePlate($plate)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frenchPlates(): iterable
    {
        yield 'SIV' => ['AB-123-CD'];
        yield 'FNI' => ['1234 AB 56'];
        yield 'FNI Corsica' => ['123 ABC 2A'];
        yield 'FNI overseas' => ['12 AB 974'];
    }

    public function testATransportFailureNeverCarriesTheToken(): void
    {
        $token = 'Sup3rS3cretT0ken';
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            throw new TransportException(\sprintf('Could not resolve host for "%s".', $url));
        });
        $logger = new RecordingLogger();
        $client = new SivClient($http, $token, new InMemorySivLookupStore(), UnlimitedQuota::create(), maxRetries: 0, retryDelayMs: 0, logger: $logger);

        try {
            $client->lookup('AA-123-BB');
            $this->fail('expected UpstreamException');
        } catch (UpstreamException $e) {
            $this->assertTrue($e->transient);
            for ($link = $e; null !== $link; $link = $link->getPrevious()) {
                $this->assertStringNotContainsString($token, $link->getMessage());
                $this->assertStringNotContainsString(rawurlencode($token), $link->getMessage());
            }
            $this->assertStringContainsString('Could not resolve host', $e->getMessage(), 'the cause stays readable');
        }

        $this->assertStringNotContainsString(rawurlencode($token), $logger->dump());
    }

    private static function idleTimeout(): \Generator
    {
        throw new TransportException('Idle timeout reached for "https://api.apiplaqueimmatriculation.com/plaque".');
        // @phpstan-ignore deadCode.unreachable (the yield makes this a generator, so MockResponse throws while streaming, not on construction)
        yield '';
    }
}
