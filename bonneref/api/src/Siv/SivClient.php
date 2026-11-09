<?php

declare(strict_types=1);

namespace App\Siv;

use App\Exception\MissingApiKeyException;
use App\Exception\RateLimitExceededException;
use App\Exception\UpstreamException;
use App\Security\OwnerRateLimiter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * apiplaqueimmatriculation.com client — French plate → SIV vehicle data.
 *
 * Every successful lookup is cached (keyed by the normalized plate, value = the full
 * envelope, same shape as prototype_1's <PLATE>.json) and never re-paid. Failed
 * lookups are never cached.
 */
final readonly class SivClient
{
    private const BASE_URI = 'https://api.apiplaqueimmatriculation.com/plaque';

    /** Model-facing name of the service — never the vendor host or the env var. */
    private const SOURCE = 'the French vehicle registry (SIV)';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $token,
        private SivLookupStore $store,
        private OwnerRateLimiter $quota,
        private int $maxRetries = 2,
        private int $retryDelayMs = 1000,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function normalizePlate(string $raw): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    /**
     * @return array<string, mixed>|null the SIV `data` payload, or null when the plate is unknown
     *
     * @throws MissingApiKeyException
     * @throws UpstreamException
     * @throws RateLimitExceededException only on a store miss: a stored plate costs nothing
     */
    public function lookup(string $plate): ?array
    {
        $plate = self::normalizePlate($plate);
        if ('' === $plate) {
            return null;
        }

        $cached = $this->store->find($plate);
        if (null !== $cached) {
            // A negative hit (unknown plate) is cached as an envelope with no `data` → resolves to null.
            return $cached['data'] ?? null;
        }

        if ('' === $this->token) {
            $this->logger->error('Plate lookup refused: API_PLAQUE_TOKEN is empty, no apiplaqueimmatriculation.com token configured.');

            throw MissingApiKeyException::forSiv();
        }

        $this->quota->consume();

        $envelope = $this->fetch($plate);

        $errorCode = (int) ($envelope['code_erreur'] ?? 200);
        $dataError = (string) ($envelope['data']['erreur'] ?? '');

        if (200 === $errorCode && '' === $dataError && \is_array($envelope['data'] ?? null)) {
            $this->store->save($plate, $envelope);

            return $envelope['data'];
        }

        // Unknown plate: the upstream signals it via data.erreur and/or a 404 error code. Cache a
        // negative marker (the envelope minus `data`) so a repeat lookup of a non-existent plate is
        // not re-billed to the paid SIV API. Transient errors below stay uncached for later retry.
        if ('' !== $dataError || 404 === $errorCode) {
            unset($envelope['data']);
            $this->store->save($plate, $envelope);

            return null;
        }

        $message = sprintf('apiplaqueimmatriculation.com error %d: %s', $errorCode, $envelope['message'] ?? '');

        throw $errorCode >= 500 ? UpstreamException::transient(self::SOURCE, $message) : UpstreamException::definitive(self::SOURCE, $message);
    }

    /**
     * One lookup, retried on transport failure only.
     *
     * The retry has to live here: Symfony's RetryableHttpClient yields a bare idle-timeout chunk
     * and returns BEFORE consulting the retry strategy (RetryableHttpClient.php:88), so the scoped
     * client's `retry_failed` never sees it — measured 2026-09-14, 1 TCP connection with
     * max_retries=2. HTTP-status retries are deliberately NOT done here: the scoped client owns
     * those, and doing both would multiply attempts against a per-call billed API.
     *
     * @return array<string, mixed>
     */
    private function fetch(string $plate): array
    {
        for ($attempt = 0;; ++$attempt) {
            try {
                $response = $this->httpClient->request('POST', self::BASE_URI, [
                    'query' => ['immatriculation' => $plate, 'token' => $this->token, 'pays' => 'FR'],
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => 15,
                ]);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
            } catch (ExceptionInterface $e) {
                if (!$e instanceof TransportExceptionInterface || $attempt >= $this->maxRetries) {
                    throw UpstreamException::transient(self::SOURCE, 'apiplaqueimmatriculation.com: '.$e->getMessage(), $e);
                }

                $this->pause($attempt);

                continue;
            }

            $envelope = json_decode($body, true);
            if (\is_array($envelope) && 200 === $status) {
                return $envelope;
            }

            $message = sprintf('apiplaqueimmatriculation.com bad response (HTTP %d)', $status);

            throw $status >= 500 || 429 === $status ? UpstreamException::transient(self::SOURCE, $message) : UpstreamException::definitive(self::SOURCE, $message);
        }
    }

    private function pause(int $attempt): void
    {
        if ($this->retryDelayMs > 0) {
            usleep($this->retryDelayMs * 2 ** $attempt * 1000);
        }
    }
}
