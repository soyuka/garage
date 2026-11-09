<?php

declare(strict_types=1);

namespace App\Transcription;

/**
 * The backend refused, died, or is simply not running.
 *
 * Exists so a missing sidecar cannot become a 500 on the dashboard. Dictation is a convenience over a
 * keyboard that still works: the controller turns this into a 503, the composer keeps
 * whatever it had, and the operator types. Never a card about a failed transcription.
 */
final class TranscriptionUnavailable extends \RuntimeException
{
}
