<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Speaks through ElevenLabs' streaming text-to-speech endpoint.
 *
 * `pcm_24000` wrapped in a placeholder {@see Wav} header, on purpose (2026-09-05): the dashboard's
 * streaming decoder (`noyau/pcm.ts`) only knows how to read WAV, chunk by chunk — headerless PCM
 * would fall to the non-WAV path (`speaker.ts`'s `decodeAudioData`), which cannot decode raw samples
 * at all, and mp3 cannot be decoded incrementally either (no `MediaSource` codec for it exists,
 * docs/voice.md). The header and the 24 kHz rate are the ones the former pocket-tts backend emitted
 * (docs/archive/pocket-tts.md), kept so the browser has one header shape and one sample rate to read.
 *
 * MEASURED CONSTRAINT (2026-08-16, docs/voice-plan.md § Prerequisite): a FREE-tier key cannot speak
 * French. Free keys see 21 voices, all `language: en`; every French voice is a shared-library voice
 * and the API answers `402 paid_plan_required` for all of them, including those whose library record
 * says `free_users_allowed: true` (that flag governs the web app, not the API). So ELEVENLABS_VOICE_ID
 * is an English voice until the account is upgraded, at which point it becomes a one-line env change.
 */
final readonly class ElevenLabsSpeechClient implements SpeechClient
{
    private const string ENDPOINT = 'https://api.elevenlabs.io/v1/text-to-speech/%s/stream';
    private const string VOICES_ENDPOINT = 'https://api.elevenlabs.io/v1/voices';
    private const string CONTENT_TYPE = 'audio/wav';
    private const int SAMPLE_RATE = 24000;
    private const float MIN_SPEED = 0.7;
    private const float MAX_SPEED = 1.2;

    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $voiceId,
        private string $model,
        private float $speed = 1.0,
    ) {
        if (!is_finite($this->speed) || self::MIN_SPEED > $this->speed || self::MAX_SPEED < $this->speed) {
            throw new \InvalidArgumentException(\sprintf('ELEVENLABS_SPEED must be between %.1f and %.1f.', self::MIN_SPEED, self::MAX_SPEED));
        }
    }

    public function stream(string $text, Locale $locale, ?string $voice = null): SpeechStream
    {
        $voiceId = $voice ?? $this->voiceId;

        if ('' === $this->apiKey || '' === $voiceId) {
            throw new SpeechUnavailable('ElevenLabs is selected but ELEVENLABS_API_KEY / ELEVENLABS_VOICE_ID are unset.');
        }

        try {
            $response = $this->http->request('POST', \sprintf(self::ENDPOINT, $voiceId), [
                'headers' => ['xi-api-key' => $this->apiKey],
                'query' => ['output_format' => \sprintf('pcm_%d', self::SAMPLE_RATE)],
                'json' => [
                    'text' => $text,
                    'model_id' => $this->model,
                    // `eleven_flash_v2_5` is multilingual, so the voice reads the SAME sentence
                    // differently depending on this. Omitted, the voice's own language wins — which
                    // is how a French line ends up read as though it were English.
                    'language_code' => $locale->value,
                    'voice_settings' => ['speed' => $this->speed],
                ],
            ]);

            // Blocks until the headers are in, which is what surfaces "refused" HERE rather than from
            // inside the generator, where no status could be chosen any more.
            $status = $response->getStatusCode();
            if (200 !== $status) {
                // 401 bad key, 402 free tier reaching for a library voice, 429 rate limit — all the
                // caller's business only insofar as "no audio this time".
                throw new SpeechUnavailable(\sprintf('ElevenLabs refused the request (HTTP %d).', $status));
            }
        } catch (HttpClientException $e) {
            throw new SpeechUnavailable('ElevenLabs could not be reached.', previous: $e);
        }

        return new SpeechStream(self::CONTENT_TYPE, $this->chunks($response));
    }

    /**
     * `GET /v1/voices` filtered to $locale (a voice with no `labels.language` at all — a cloned or
     * multilingual voice — is kept regardless of locale, since it carries no language claim to
     * contradict). Best-effort like everything else on this seam: a broken call must not take the
     * whole picker, or the request it gates, down with it.
     */
    public function voices(Locale $locale): array
    {
        $fallback = [new SpeechVoice($this->voiceId, 'ElevenLabs')];

        try {
            $response = $this->http->request('GET', self::VOICES_ENDPOINT, [
                'headers' => ['xi-api-key' => $this->apiKey],
            ]);

            if (200 !== $response->getStatusCode()) {
                return $fallback;
            }

            $voices = $response->toArray()['voices'] ?? [];
        } catch (HttpClientException) {
            // Unreachable, refused, or the body wasn't valid JSON — toArray() throws for all three.
            return $fallback;
        }

        $matched = [];
        foreach ($voices as $voice) {
            $id = $voice['voice_id'] ?? null;
            $name = $voice['name'] ?? null;
            if (!\is_string($id) || '' === $id || !\is_string($name) || '' === $name) {
                continue;
            }

            $labels = \is_array($voice['labels'] ?? null) ? $voice['labels'] : [];
            if (\array_key_exists('language', $labels) && $labels['language'] !== $locale->value) {
                continue;
            }

            $matched[] = new SpeechVoice($id, self::label($name));
        }

        if ([] === $matched) {
            return $fallback;
        }

        // The configured default sorts first — it is the one `stream()` falls back to when no voice
        // is requested, so the picker built on top of this should offer it as the obvious choice.
        $default = null;
        $others = [];
        foreach ($matched as $entry) {
            if (null === $default && $entry->id === $this->voiceId) {
                $default = $entry;
                continue;
            }
            $others[] = $entry;
        }

        return null !== $default ? [$default, ...$others] : $matched;
    }

    /** "Claire - Conversationnel" → "Claire": the use-case suffix ElevenLabs names carry is noise here. */
    private static function label(string $name): string
    {
        return trim((preg_split('/[-–]/u', $name, 2) ?: [$name])[0]);
    }

    /** @return \Generator<int, string, mixed, string> */
    private function chunks(ResponseInterface $response): \Generator
    {
        $header = Wav::header(self::SAMPLE_RATE);
        yield $header;

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
            throw new SpeechUnavailable('ElevenLabs dropped the stream.', previous: $e);
        }

        return Wav::withRealLengths($header.$body);
    }
}
