<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * One voice a backend can speak with: the id `stream()`'s `$voice` parameter accepts, and a label
 * for a picker built on top of {@see SpeechClient::voices()} (the dashboard). Deliberately as thin as
 * {@see SpokenAudio} — the format is a property of the backend, and so is the set of voices it offers.
 */
final readonly class SpeechVoice
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
