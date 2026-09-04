<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * One spoken line, and the format it came out in.
 *
 * The format rides WITH the bytes because it is a property of the backend, not of the endpoint:
 * ElevenLabs returns mp3, `pocket-tts` returns 24 kHz mono WAV, and Google Chirp can be told to
 * return either. {@see \App\Controller\SpeechController} used to hardcode `audio/mpeg`, which was an
 * ElevenLabs-shaped assumption that only became visible when a second backend arrived — exactly the
 * kind of leak docs/voice-plan.md § Phase 6 exists to catch while it is still one line to fix.
 */
final readonly class SpokenAudio
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
