<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * The canonical 44-byte PCM WAV header, and the patch that turns a streamed placeholder into a real
 * one once the true length is known.
 *
 * A streaming backend cannot know its own byte count when it writes the header — the true length
 * only exists once the last chunk has arrived — so `header()` always emits the SAME placeholder
 * sizes pocket-tts was measured streaming on the wire (docs/voice.md, 2026-09-05: RIFF 2 000 000 036,
 * data 2 000 000 000), regardless of which backend calls it. That is the point: the dashboard's
 * streaming decoder (`noyau/pcm.ts`) sniffs RIFF/WAVE and never reads the declared lengths, so
 * ElevenLabs' streamed PCM and pocket-tts's WAV wire the identical header shape and the browser
 * cannot tell the two backends apart.
 *
 * `withRealLengths()` patches the COMPLETED body — the copy a cache stores — once the true length is
 * known; the wire itself keeps the placeholder forever, because bytes already sent cannot be
 * rewritten. Moved here from `PocketTtsSpeechClient` verbatim (2026-09-05) so both backends share one
 * implementation instead of growing two copies of the same bit-twiddling.
 */
final readonly class Wav
{
    private const int PLACEHOLDER_RIFF_SIZE = 2000000036;
    private const int PLACEHOLDER_DATA_SIZE = 2000000000;

    private function __construct()
    {
    }

    public static function header(int $sampleRate, int $channels = 1, int $bitsPerSample = 16): string
    {
        $blockAlign = intdiv($bitsPerSample, 8) * $channels;
        $byteRate = $sampleRate * $blockAlign;

        return 'RIFF'.pack('V', self::PLACEHOLDER_RIFF_SIZE).'WAVE'
            .'fmt '.pack('V', 16).pack('vvVVvv', 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
            .'data'.pack('V', self::PLACEHOLDER_DATA_SIZE);
    }

    /**
     * Anything that is not RIFF/WAVE passes through untouched — a non-WAV body reaching this would be
     * a caller bug, not something to repair.
     */
    public static function withRealLengths(string $wav): string
    {
        if (!str_starts_with($wav, 'RIFF') || !str_starts_with(substr($wav, 8, 4), 'WAVE')) {
            return $wav;
        }

        $data = strpos($wav, 'data', 12);
        if (false === $data) {
            return $wav;
        }

        return substr_replace(
            substr_replace($wav, pack('V', \strlen($wav) - 8), 4, 4),
            pack('V', \strlen($wav) - $data - 8),
            $data + 4,
            4,
        );
    }
}
