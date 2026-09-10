<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Speaks through a local Kyutai `pocket-tts` server (`pocket-tts serve`, FastAPI, `POST /tts`).
 *
 * The reason it exists: no API key, no per-line network trip off the machine, no monthly credit
 * budget, and a demo that still speaks with the wifi unplugged — which retires the top risk in
 * docs/voice-plan.md § Risks, where the conference network dying meant every line went silent.
 *
 * ONE SERVER PER LANGUAGE. `serve` binds a single model at process start (`--language french_24l`),
 * so `$endpoints` maps a locale onto the server that speaks it. A locale with no entry raises
 * {@see SpeechUnavailable} rather than borrowing another language's server: routing English at the
 * French model does not fail, it reads English words with French phonetics, and a wrong-sounding
 * voice is far harder to diagnose than a silent one.
 *
 * MEASURED on this machine (2026-09-05, AMD Ryzen 7 PRO 6850U, CPU-only, `french_24l` + `--quantize`,
 * docs/voice.md): headers in 3–9 ms, first audio bytes at 243 ms (short line) / 413 ms (medium), then
 * 3840 B = 80 ms of audio every ~60 ms — real-time factor ≈ 0.77. A 4.2 s line is complete after
 * 3.2 s, a 10 s line after 8.4 s. Buffering that body (what this did until 2026-09-05) made the
 * browser's first byte wait for the last one; passing the chunks through makes the first sound
 * arrive ~250–400 ms after the request instead. A second synthesis running concurrently pushes the
 * factor to ~1.1 — slower than playback — which is why nothing else may synthesize the same line.
 */
final readonly class PocketTtsSpeechClient implements SpeechClient
{
    /** `serve` emits 24 kHz mono PCM WAV. There is no mp3 path, and none is wanted for one sentence. */
    private const string CONTENT_TYPE = 'audio/wav';

    /**
     * @param array<string, string> $endpoints locale value => base URL of the server speaking it;
     *                                         an empty or missing entry means that language is mute
     */
    public function __construct(
        private HttpClientInterface $http,
        private array $endpoints,
    ) {
    }

    public function stream(string $text, Locale $locale): SpeechStream
    {
        $base = trim($this->endpoints[$locale->value] ?? '');

        if ('' === $base) {
            throw new SpeechUnavailable(\sprintf('No pocket-tts server is configured for "%s".', $locale->value));
        }

        // FastAPI declares the endpoint with an UploadFile alongside the text, so the body has to be
        // real multipart — a urlencoded form is rejected by the file-carrying signature.
        $form = new FormDataPart(['text' => $text]);

        try {
            $response = $this->http->request('POST', rtrim($base, '/').'/tts', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
            ]);

            // Blocks until the headers are in (~5 ms) and is what surfaces "unreachable" here rather
            // than from inside the generator, where no status could be chosen any more.
            if (200 !== $response->getStatusCode()) {
                throw new SpeechUnavailable(\sprintf('pocket-tts refused the request (HTTP %d).', $response->getStatusCode()));
            }
        } catch (HttpClientException $e) {
            throw new SpeechUnavailable('pocket-tts could not be reached.', previous: $e);
        }

        return new SpeechStream(self::CONTENT_TYPE, $this->chunks($response));
    }

    /** @return \Generator<int, string, mixed, string> */
    private function chunks(ResponseInterface $response): \Generator
    {
        $body = '';

        try {
            foreach ($this->http->stream($response) as $chunk) {
                $content = $chunk->getContent();
                if ('' === $content) {
                    continue;
                }

                $body .= $content;
                yield $content;
            }
        } catch (HttpClientException $e) {
            throw new SpeechUnavailable('pocket-tts dropped the stream.', previous: $e);
        }

        // Stamps the true byte counts into the COMPLETED body — the copy the cache keeps. `serve`
        // streams, so it cannot know the length when it writes the header and emits a 2 GB
        // placeholder in both the RIFF and `data` chunk sizes; a file at rest with an 11-hour header
        // is a broken artifact for anything that trusts headers (an `<audio>` element played one to
        // the real EOF and raised MEDIA_ERR_ABORTED). Moved to {@see Wav} (2026-09-05) so ElevenLabs'
        // streamed PCM gets the identical treatment instead of a second copy of the same patch.
        return Wav::withRealLengths($body);
    }
}
