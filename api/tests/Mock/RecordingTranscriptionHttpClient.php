<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The STT sidecar transport in the test env. The twin of {@see RecordingSpeechHttpClient}, and its own
 * double for the same reason: these tests assert on the OUTGOING request — that the recording's bytes
 * reach the backend unaltered and that the resolved language rides along — which a fixture-file router
 * cannot express.
 *
 * No test reaches api.elevenlabs.io: a transcription is billed by the minute out of the same credit
 * pool the voice spends from, and a suite that spends it is a suite nobody runs.
 */
final class RecordingTranscriptionHttpClient implements HttpClientInterface
{
    /** What the fake backend heard. Any test asserting on a transcript expects this string. */
    public const string TRANSCRIPT = 'Enregistre une vidange sur la Mazda 6.';

    /** @var list<array{method: string, url: string, options: array<string, mixed>, body: string}> */
    public array $requests = [];

    /** Flipped by a test to rehearse a backend failure (a 503 sidecar, an OOM-killed model). */
    public int $status = 200;

    /** Flipped by a test to rehearse silence — a mis-press, a room too loud, a muted mic. */
    public string $transcript = self::TRANSCRIPT;

    private readonly MockHttpClient $mock;

    public function __construct()
    {
        $this->mock = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            // A multipart body reaches the transport as a chunk-yielding closure over a generator,
            // and the client consumes it on the way out — read later, from the recorded options, it
            // is already exhausted and drains to ''. So it is drained HERE, at send time, which is
            // the only moment the bytes exist. Nothing downstream needs the body: the response is
            // canned, and a drained request body simply means the fake vendor read its input.
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
                'body' => self::drain($options['body'] ?? ''),
            ];

            return new MockResponse((string) json_encode(['text' => $this->transcript]), [
                'http_code' => $this->status,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });
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
        return $this;
    }

    /**
     * The last outgoing request, or null when the endpoint short-circuited before calling the backend.
     *
     * @return array{method: string, url: string, options: array<string, mixed>, body: string}|null
     */
    public function lastRequest(): ?array
    {
        return $this->requests[array_key_last($this->requests)] ?? null;
    }

    /** The last outgoing body, drained at send time — the whole multipart envelope as one string. */
    public function lastBody(): string
    {
        return $this->lastRequest()['body'] ?? '';
    }

    /** @param mixed $body whatever shape the client normalized the outgoing body into */
    private static function drain(mixed $body): string
    {
        if (\is_string($body)) {
            return $body;
        }

        if (\is_callable($body)) {
            $drained = '';
            while ('' !== ($chunk = $body(16384))) {
                $drained .= $chunk;
            }

            return $drained;
        }

        if (is_iterable($body)) {
            $drained = '';
            foreach ($body as $chunk) {
                $drained .= $chunk;
            }

            return $drained;
        }

        return '';
    }
}
