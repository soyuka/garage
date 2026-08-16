<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * Which provider speaks, selected at runtime from TTS_BACKEND by {@see SelectableSpeechClient}.
 *
 *  - ElevenLabs: `eleven_flash_v2_5`, ~75 ms, the best French on the measured field
 *    ({@see ElevenLabsSpeechClient}).
 *  - Google: Chirp 3 HD (`fr-FR-Chirp3-HD-*`), not yet implemented — docs/voice-plan.md § Phase 6.
 *
 * A case with no locator entry is a hard failure at first call, not a silent fallback: a dashboard
 * that quietly speaks in the wrong voice is worse than one that does not speak.
 */
enum SpeechBackend: string
{
    case ElevenLabs = 'elevenlabs';
    case Google = 'google';
}
