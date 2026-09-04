<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
 * MEASURED on this machine (2026-09-04, AMD Ryzen 7 PRO 6850U, CPU-only, `french_24l` + `--quantize`):
 * a warm resident server answers a short line in ~1.5–2.0 s and a two-sentence line in ~3.9–4.3 s.
 * Cold CLI invocations are 3× worse because each reloads the model — which is exactly why this talks
 * to a long-running server instead of shelling out per line.
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

    public function synthesize(string $text, Locale $locale): SpokenAudio
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

            if (200 !== $response->getStatusCode()) {
                throw new SpeechUnavailable(\sprintf('pocket-tts refused the request (HTTP %d).', $response->getStatusCode()));
            }

            return new SpokenAudio(self::withRealLengths($response->getContent()), self::CONTENT_TYPE);
        } catch (HttpClientException $e) {
            throw new SpeechUnavailable('pocket-tts could not be reached.', previous: $e);
        }
    }

    /**
     * Stamp the true byte counts into a streamed WAV header.
     *
     * `serve` streams, so it cannot know the length when it writes the header and emits a 2 GB
     * placeholder in both the RIFF and `data` chunk sizes. An `<audio>` element trusts that: it reads
     * a 6-second line as an 11-hour stream, plays to the real EOF, and Firefox raises
     * MEDIA_ERR_ABORTED — which the dashboard's audition reads as an autoplay refusal and latches
     * itself closed on. So one bad header does not merely cut a line, it mutes the ones behind it.
     *
     * Buffering the body is what makes the fix possible and is why it belongs here rather than in the
     * controller: this is the only place that holds the vendor's bytes and knows they came from
     * pocket-tts. Anything that is not RIFF/WAVE passes through untouched.
     */
    private static function withRealLengths(string $wav): string
    {
        if (!str_starts_with($wav, 'RIFF') || !str_starts_with(substr($wav, 8, 4), 'WAVE')) {
            return $wav;
        }

        $data = strpos($wav, 'data', 12);
        if (false === $data) {
            return $wav;
        }

        return substr_replace(
            substr_replace($wav, pack('V', \strlen($wav) - 8), 4, 4),
            pack('V', \strlen($wav) - $data - 8),
            $data + 4,
            4,
        );
    }
}
