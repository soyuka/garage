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
 * pocket-tts was the swap docs/voice-plan.md § Phase 6 asks for, and it found the one leak: the
 * return type used to be raw mp3 bytes, so the controller stamped `audio/mpeg` on a WAV body. That is
 * what {@see SpokenAudio} fixes. The seam held everywhere else.
 *
 * @see \App\Agent\WebSearch\WebSearchClient the proven seam this deliberately mirrors
 */
interface SpeechClient
{
    /**
     * @param Locale $locale the language the text is IN — not a hint, a pronunciation instruction:
     *                       a multilingual voice reads the same sentence differently depending on it
     *
     * @return SpokenAudio the audio bytes and the format they are in — the format is the backend's,
     *                      not the endpoint's, so it travels with the payload rather than being
     *                      assumed downstream
     *
     * @throws SpeechUnavailable when the vendor refuses or is unreachable — never a 500 for the caller
     */
    public function synthesize(string $text, Locale $locale): SpokenAudio;
}
