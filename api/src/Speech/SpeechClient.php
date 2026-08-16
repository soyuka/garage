<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;

/**
 * Turns one line of text into spoken audio. This is the seam that decouples the dashboard's voice
 * from the vendor providing it: the browser only ever calls same-origin `/tts`, so the API key stays
 * server-side and the provider is an implementation detail nobody downstream can observe.
 *
 * One implementation per backend ({@see ElevenLabsSpeechClient}). Adding Google Chirp 3 HD is meant
 * to cost exactly three things and nothing else: a class implementing this interface, a case on
 * {@see SpeechBackend}, and a line in the `!service_locator` in services.yaml. If a second provider
 * ever needs more than that, this seam is wrong — see docs/voice-plan.md § Phase 6, which exists
 * specifically to prove the swap while it is still cheap.
 *
 * @see \App\Agent\WebSearch\WebSearchClient the proven seam this deliberately mirrors
 */
interface SpeechClient
{
    /**
     * @param Locale $locale the language the text is IN — not a hint, a pronunciation instruction:
     *                       a multilingual voice reads the same sentence differently depending on it
     *
     * @return string the audio bytes (mp3)
     *
     * @throws SpeechUnavailable when the vendor refuses or is unreachable — never a 500 for the caller
     */
    public function synthesize(string $text, Locale $locale): string;
}
