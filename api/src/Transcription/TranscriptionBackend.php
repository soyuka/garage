<?php

declare(strict_types=1);

namespace App\Transcription;

/**
 * Which backend transcribes, from env `STT_BACKEND`. The case values are the service-locator keys in
 * services.yaml, so adding a provider is an enum case plus a locator line.
 *
 * Only the case that is actually implemented is listed — the voice's enum carrying an unimplemented
 * `google` is a wart, not a precedent. A local whisper sidecar is the planned second backend and is
 * deferred, not cancelled: measured on this CPU it was either approximate (`small`: « Masda » for
 * Mazda) or slow and large (1.6 GB, ~7 s for a 4 s line). See docs/dictation-plan.md § Measured.
 */
enum TranscriptionBackend: string
{
    case ElevenLabs = 'elevenlabs';
}
