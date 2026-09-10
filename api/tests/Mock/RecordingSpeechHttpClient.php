<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The vendor TTS transport in the test env. Separate from {@see FixtureHttpClient} (which decorates
 * `http_client` wholesale for the catalog upstreams) because these tests assert on the OUTGOING
 * request — the api key header, the voice in the path, the model in the body — not just on what
 * comes back. A fixture-file router cannot carry those assertions.
 *
 * No test ever reaches api.elevenlabs.io: a credit is real money and a suite that spends it is a
 * suite nobody runs.
 */
final class RecordingSpeechHttpClient implements HttpClientInterface
{
    /** Stand-in for the mp3 body. Not valid audio — nothing in the stack decodes it. */
    public const string AUDIO = "ID3\x04\x00fake-mp3-bytes";

    /**
     * `GET /v1/voices` answer for the voice-selection tests. `test-voice-id` is the account's
     * configured default (`ELEVENLABS_VOICE_ID` in `.env.test`), and sorts first for that reason —
     * {@see \App\Speech\SpeechTest} relies on both facts.
     */
    public const array VOICES = [
        ['voice_id' => 'test-voice-id', 'name' => 'Claire - Conversationnel', 'labels' => ['language' => 'fr']],
        ['voice_id' => 'other-voice-id', 'name' => 'Louis', 'labels' => ['language' => 'fr']],
    ];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** Flipped by a test to rehearse a vendor failure (401 bad key, 429 rate limit). */
    public int $status = 200;

    private readonly MockHttpClient $mock;

    public function __construct()
    {
        $this->mock = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if ('GET' === $method && str_contains($url, '/v1/voices')) {
                return new MockResponse((string) json_encode(['voices' => self::VOICES]), [
                    'http_code' => $this->status,
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }

            return new MockResponse(self::AUDIO, [
                'http_code' => $this->status,
                'response_headers' => ['content-type' => 'audio/mpeg'],
            ]);
        });
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

    /** The last outgoing request, or null when the endpoint short-circuited before calling the vendor. */
    public function lastRequest(): ?array
    {
        return $this->requests[array_key_last($this->requests)] ?? null;
    }

    /** @return array<string, mixed> the decoded JSON body of the last outgoing request */
    public function lastBody(): array
    {
        $body = $this->lastRequest()['options']['body'] ?? '{}';

        return json_decode(\is_string($body) ? $body : '{}', true, 512, \JSON_THROW_ON_ERROR);
    }
}
