<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;

/**
 * Turns one line of text into spoken audio. This is the seam that decouples the dashboard's voice
 * from the vendor providing it: the browser only ever calls same-origin `/tts`, so the API key stays
 * server-side and the provider is an implementation detail nobody downstream can observe.
 *
 * One implementation per backend ({@see ElevenLabsSpeechClient}, {@see PocketTtsSpeechClient}). A new
 * provider is meant to cost exactly three things and nothing else: a class implementing this
 * interface, a case on {@see SpeechBackend}, and a line in the `!service_locator` in services.yaml.
 *
 * pocket-tts was the swap docs/voice-plan.md § Phase 6 asks for, and it found two leaks. The return
 * type used to be raw mp3 bytes, so the controller stamped `audio/mpeg` on a WAV body — fixed by
 * {@see SpokenAudio} carrying its format. And it used to be the WHOLE body, which made a streaming
 * backend wait for its own last chunk before the browser heard the first — fixed by returning
 * {@see SpeechStream} (2026-09-05). The seam held everywhere else.
 *
 * @see \App\Agent\WebSearch\WebSearchClient the proven seam this deliberately mirrors
 */
interface SpeechClient
{
    /**
     * @param Locale $locale the language the text is IN — not a hint, a pronunciation instruction:
     *                       a multilingual voice reads the same sentence differently depending on it
     *
     * @return SpeechStream the audio as the backend produces it, in the backend's own format
     *
     * @throws SpeechUnavailable when the vendor refuses or is unreachable — thrown HERE, before a
     *                           chunk is pulled, so the caller can still answer with a status; a
     *                           backend dying mid-line throws it from the generator instead
     */
    public function stream(string $text, Locale $locale): SpeechStream;
}
