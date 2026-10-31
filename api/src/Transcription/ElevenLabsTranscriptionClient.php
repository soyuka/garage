<?php

declare(strict_types=1);

namespace App\Transcription;

use App\I18n\Locale;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Transcribes through ElevenLabs Scribe (`POST /v1/speech-to-text`, multipart, field `file`).
 *
 * Chosen over a local whisper sidecar after measuring both: on this CPU the local route was either
 * approximate (`small`, 464 MB, spelled the operator's own Mazda "Masda") or slow and large
 * (`large-v3-turbo`, 1.6 GB, ~7 s for a 4 s line — slower than real time on a control the operator is
 * holding). docs/dictation-plan.md § Measured carries the tables; the sidecar stays the planned
 * second backend, which is the whole reason this class sits behind {@see TranscriptionClient}.
 *
 * Same account and same key as the voice ({@see \App\Speech\ElevenLabsSpeechClient}), and worth
 * knowing: ElevenLabs bills one shared monthly credit pool across TTS and STT at ~330 credits per
 * minute of audio, so dictation and the voice spend from the same purse. The voice's
 * `TTS_MONTHLY_CHAR_BUDGET` counter cannot see this side of it.
 */
final readonly class ElevenLabsTranscriptionClient implements TranscriptionClient
{
    private const string ENDPOINT = 'https://api.elevenlabs.io/v1/speech-to-text';

    public function __construct(
        private HttpClientInterface $http,
        #[\SensitiveParameter]
        private string $apiKey,
        private string $model,
    ) {
    }

    public function transcribe(string $audio, string $contentType, Locale $locale, array $keyterms = []): string
    {
        $form = new FormDataPart([
            // The recording as the browser produced it. The vendor accepts WebM/Opus directly, so
            // nothing here re-encodes: a lossy second pass would cost accuracy invisibly.
            'file' => new DataPart($audio, 'dictation.'.self::extension($contentType), $contentType),
            'model_id' => $this->model,
            // ISO 639-1, which is exactly what Locale's value already is (App\I18n\Locale) — nothing
            // translates between them. Sent always rather than relying on auto-detect: one dictated
            // sentence is little evidence, and a French instruction detected as English does not come
            // back accented, it comes back as different words.
            'language_code' => $locale->value,
            // Defaults to TRUE at the vendor, which would decorate a dictated instruction with
            // "(laughter)" / "(applause)" markers. The transcript goes into the composer as the
            // operator's words; an event tag there is noise they would have to delete by hand.
            'tag_audio_events' => 'false',
            // Bias toward the operator's own garage, ONE FIELD PER TERM — `[['keyterms' => 'MAZDA6'],
            // ['keyterms' => 'CLIO'], …]`, which is how FormDataPart spells a repeated field name (an
            // integer key holding a single name => value pair; a nested ARRAY under one key would
            // spell `keyterms[0]`, `keyterms[1]`, which is a different field each time).
            //
            // NOT a JSON array. Measured against the live vendor on 2026-09-11: each `keyterms` field
            // is read as one keyword verbatim, so `["MAZDA6","CLIO"]` arrives as a single keyword
            // containing `[`, `"` and `]` and the whole request is refused with
            // `400 {"status":"invalid_keyword","message":"Some keyword contains invalid characters"}`.
            // It is the whole request, not the field: dictation was dead for anyone owning a car. The
            // same probe with plain per-field values passes validation, accents, typographic
            // apostrophes and hyphens included ({@see Keyterms} strips the characters that do not).
            //
            // Sent only when there is something to bias toward: the vendor adds a flat +20 % to the
            // call whenever `keyterms` is present, so an empty list must be an absent field.
            ...array_map(
                static fn (string $term): array => ['keyterms' => $term],
                Keyterms::normalize($keyterms),
            ),
        ]);

        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'headers' => $form->getPreparedHeaders()->toArray() + ['xi-api-key' => $this->apiKey],
                'body' => $form->bodyToIterable(),
            ]);

            // Anything but a 200 is the vendor refusing: a bad key or a key missing the
            // `speech_to_text` permission (401), a body or a parameter it will not take (400/413), a
            // concurrency or quota wall (429). The controller already validated the request, so none
            // of these is recoverable here — they all end as one silent 503 and the operator types.
            //
            // The BODY is carried into the message, and it is the whole point of this branch: every
            // refusal looks identical from the dashboard (a mic that transcribes nothing), so the
            // vendor's own sentence is the only thing that separates a wrong parameter from an
            // unentitled key. Both of those were live on 2026-09-11 and neither was visible — the
            // logs said "HTTP 400" and nothing else, and the cause was found by re-probing the vendor
            // by hand. Truncated because an error body is a sentence, and a proxy's HTML page is not.
            if (200 !== $response->getStatusCode()) {
                throw new TranscriptionUnavailable(\sprintf('Scribe refused the recording (HTTP %d): %s', $response->getStatusCode(), mb_substr(trim($response->getContent(throw: false)), 0, 400) ?: '(no detail)'));
            }

            /** @var array{text?: string} $payload */
            $payload = $response->toArray();
        } catch (HttpClientException $e) {
            throw new TranscriptionUnavailable('Scribe could not be reached.', previous: $e);
        }

        // A recording with no speech in it answers 200 with an empty `text`. That is a normal outcome
        // of a mic left open on a quiet room, not an error, and it must stay one all the way to the
        // composer.
        return trim($payload['text'] ?? '');
    }

    /**
     * A filename for the multipart part. The part carries the real media type too, so this is only
     * belt-and-braces for a vendor that sniffs the extension — `audio/webm;codecs=opus` → `webm`.
     */
    private static function extension(string $contentType): string
    {
        $subtype = strtolower(trim(explode(';', explode('/', $contentType, 2)[1] ?? '', 2)[0]));

        return match ($subtype) {
            // The one place the subtype is not the extension anybody expects.
            'mpeg' => 'mp3',
            '' => 'webm',
            default => $subtype,
        };
    }
}
