<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Speaks through ElevenLabs' text-to-speech endpoint.
 *
 * `mp3_22050_32` on purpose: the payload is one sentence read once over a workshop speaker, so 32 kbps
 * mono is inaudibly different from anything larger and materially faster to reach the browser.
 *
 * MEASURED CONSTRAINT (2026-08-16, docs/voice-plan.md § Prerequisite): a FREE-tier key cannot speak
 * French. Free keys see 21 voices, all `language: en`; every French voice is a shared-library voice
 * and the API answers `402 paid_plan_required` for all of them, including those whose library record
 * says `free_users_allowed: true` (that flag governs the web app, not the API). So ELEVENLABS_VOICE_ID
 * is an English voice until the account is upgraded, at which point it becomes a one-line env change.
 */
final readonly class ElevenLabsSpeechClient implements SpeechClient
{
    private const string ENDPOINT = 'https://api.elevenlabs.io/v1/text-to-speech/%s';

    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $voiceId,
        private string $model,
    ) {
    }

    public function synthesize(string $text, Locale $locale): string
    {
        if ('' === $this->apiKey || '' === $this->voiceId) {
            throw new SpeechUnavailable('ElevenLabs is selected but ELEVENLABS_API_KEY / ELEVENLABS_VOICE_ID are unset.');
        }

        try {
            $response = $this->http->request('POST', \sprintf(self::ENDPOINT, $this->voiceId), [
                'headers' => ['xi-api-key' => $this->apiKey],
                'query' => ['output_format' => 'mp3_22050_32'],
                'json' => [
                    'text' => $text,
                    'model_id' => $this->model,
                    // `eleven_flash_v2_5` is multilingual, so the voice reads the SAME sentence
                    // differently depending on this. Omitted, the voice's own language wins — which
                    // is how a French line ends up read as though it were English.
                    'language_code' => $locale->value,
                ],
            ]);

            $status = $response->getStatusCode();
            if (200 !== $status) {
                // 401 bad key, 402 free tier reaching for a library voice, 429 rate limit — all the
                // caller's business only insofar as "no audio this time".
                throw new SpeechUnavailable(\sprintf('ElevenLabs refused the request (HTTP %d).', $status));
            }

            return $response->getContent();
        } catch (HttpClientException $e) {
            throw new SpeechUnavailable('ElevenLabs could not be reached.', previous: $e);
        }
    }
}
