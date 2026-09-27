<?php

declare(strict_types=1);

namespace App\Transcription;

use App\I18n\Locale;

/**
 * Turns one recording into the words it contains. The input twin of {@see \App\Speech\SpeechClient},
 * and deliberately the same seam shape: the browser only ever posts to same-origin `/stt`, so where
 * the audio is actually transcribed — a container on the compose network, a vendor, a model on this
 * machine — is an implementation detail nothing downstream can observe.
 *
 * A new backend is meant to cost exactly three things: a class implementing this interface, a case on
 * {@see TranscriptionBackend}, and a line in the `!service_locator` in services.yaml.
 *
 * There is no caching decorator here and that is a decision, not an omission: `CachingSpeechClient`
 * pays off because the same sentence is spoken again and again, whereas no two recordings are ever
 * byte-identical. A cache on this side would be a disk full of single-use entries.
 *
 * @see \App\Speech\SpeechClient the output twin, whose docblock discipline this follows
 */
interface TranscriptionClient
{
    /**
     * @param string  $audio       the recording exactly as the browser produced it — never re-encoded
     *                             on the way in: a lossy second pass would degrade accuracy
     *                             invisibly, and a worse transcript looks just like a worse model
     * @param string  $contentType the recording's own media type (`audio/webm;codecs=opus` from a
     *                             browser MediaRecorder), passed through so the backend decodes it
     *                             rather than sniffing
     * @param Locale  $locale      the language being SPOKEN — not a hint: transcribed as the wrong
     *                             language, a French sentence does not come back accented, it comes
     *                             back as different words
     * @param list<string> $keyterms vocabulary to bias recognition toward — the operator's own
     *                               vehicles and the garage's terms ({@see GarageVocabulary}), since
     *                               proper nouns are the measured error class. Empty means send no
     *                               bias at all, which for a metered backend is also the cheaper call
     *
     * @return string the operator's words verbatim, or `''` when the recording held no speech.
     *                Silence is a normal outcome of a push-to-talk control, never an error
     *
     * @throws TranscriptionUnavailable when the backend refuses or cannot be reached
     */
    public function transcribe(string $audio, string $contentType, Locale $locale, array $keyterms = []): string;
}
