<?php

declare(strict_types=1);

namespace App\Siv;

use App\Exception\InvalidPlateException;
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

    /** SIV (2009+): no I, O or U, which the registry never issues. */
    public const string SIV_PLATE = '/^([A-HJ-NP-TV-Z]{2})(\d{3})([A-HJ-NP-TV-Z]{2})$/';

    /** FNI (before 2009): number, letters, département (2A/2B for Corsica, 97x overseas). */
    public const string FNI_PLATE = '/^\d{1,4}[A-Z]{1,3}(\d{2}|2A|2B|97\d)$/';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[\SensitiveParameter] private string $token,
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

    public static function isFrenchPlate(string $normalized): bool
    {
        return 1 === preg_match(self::SIV_PLATE, $normalized) || 1 === preg_match(self::FNI_PLATE, $normalized);
    }

    /**
     * @return array<array-key, mixed>|null the SIV `data` payload, or null when the plate is unknown
     *
     * @throws InvalidPlateException      before the store, the quota and the paid call
     * @throws MissingApiKeyException
     * @throws UpstreamException
     * @throws RateLimitExceededException only on a store miss: a stored plate costs nothing
     */
    public function lookup(string $plate): ?array
    {
        $normalized = self::normalizePlate($plate);
        if (!self::isFrenchPlate($normalized)) {
            throw InvalidPlateException::for($plate);
        }
        $plate = $normalized;

        $cached = $this->store->find($plate);
        if (null !== $cached) {
            // A negative hit (unknown plate) is cached as an envelope with no `data` → resolves to null.
            return self::data($cached);
        }

        if ('' === $this->token) {
            $this->logger->error('Plate lookup refused: API_PLAQUE_TOKEN is empty, no apiplaqueimmatriculation.com token configured.');

            throw MissingApiKeyException::forSiv();
        }

        $this->quota->consume();

        $envelope = $this->fetch($plate);

        $errorCode = (int) ($envelope['code_erreur'] ?? 200);
        $dataError = (string) ($envelope['data']['erreur'] ?? '');

        $data = self::data($envelope);
        if (200 === $errorCode && '' === $dataError && null !== $data) {
            $this->store->save($plate, $envelope);

            return $data;
        }

        // Unknown plate: the upstream signals it via data.erreur and/or a 404 error code. Cache a
        // negative marker (the envelope minus `data`) so a repeat lookup of a non-existent plate is
        // not re-billed to the paid SIV API. Transient errors below stay uncached for later retry.
        if ('' !== $dataError || 404 === $errorCode) {
            unset($envelope['data']);
            $this->store->save($plate, $envelope);

            return null;
        }

        $detail = $envelope['message'] ?? '';
        $message = $this->redact(sprintf('apiplaqueimmatriculation.com error %d: %s', $errorCode, \is_scalar($detail) ? $detail : ''));

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
     * @return array<array-key, mixed>
     */
    private function fetch(string $plate): array
    {
        $attempt = 0;
        while (true) {
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
                    // Not chained: the vendor only takes the token in the query string, which every transport
                    // message quotes with the URL, and an error listener logs the whole chain.
                    throw UpstreamException::transient(self::SOURCE, 'apiplaqueimmatriculation.com: '.$this->redact($e->getMessage()));
                }

                $this->pause($attempt++);

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

    private function redact(string $message): string
    {
        $message = (string) preg_replace('/([?&]token=)[^&"\'\s]*/i', '$1***', $message);

        return '' === $this->token ? $message : str_replace([$this->token, rawurlencode($this->token)], '***', $message);
    }

    private function pause(int $attempt): void
    {
        if ($this->retryDelayMs > 0) {
            usleep(max(0, $this->retryDelayMs * 2 ** $attempt * 1000));
        }
    }

    /**
     * @param array<array-key, mixed> $envelope
     *
     * @return array<array-key, mixed>|null
     */
    private static function data(array $envelope): ?array
    {
        $data = $envelope['data'] ?? null;

        return \is_array($data) ? $data : null;
    }
}
