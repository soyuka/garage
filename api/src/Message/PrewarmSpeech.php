<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Fired the instant a `toast` render intent is published (both drivers — see
 * {@see \App\Agent\Ui\RenderIntentTools::toast()}), so synthesis for that exact line starts BEFORE
 * the frame reaches the browser (dashboard/CHOREOGRAPHY.md § "The voice starts before the browser
 * asks"). The worker synthesizes through the same cached {@see \App\Speech\SpeechClient} the
 * controller uses, so the browser's later `POST /tts` lands a cache hit — this is the only way the
 * FIRST line of a turn can be fast; no amount of client-side prefetch can start before the frame
 * arrives.
 *
 * Unconditional by design: gating this on whether anyone has the audition on would need plumbing
 * that can go stale, so every toast is prewarmed regardless of whether it is ever heard.
 */
final readonly class PrewarmSpeech
{
    public function __construct(
        public string $text,
        public string $locale,
    ) {
    }
}
