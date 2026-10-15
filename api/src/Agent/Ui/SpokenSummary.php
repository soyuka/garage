<?php

declare(strict_types=1);

namespace App\Agent\Ui;

/**
 * Keeps a line the workshop voice reads aloud under a character cap without ever cutting a
 * sentence in half: whole sentences while they fit, a word-boundary cut with an ellipsis only when
 * the first sentence alone overflows. The TTS endpoint refuses anything past its own cap, so a
 * model that ignores the schema's maxLength must degrade to a shorter line, not to silence.
 */
final class SpokenSummary
{
    public const int FINDING_MAX = 200;
    public const int TOAST_MAX = 140;
    public const int NARRATION_MAX = 100;

    public static function fit(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [$text];

        $kept = '';
        foreach ($sentences as $sentence) {
            $candidate = '' === $kept ? $sentence : $kept.' '.$sentence;
            if (mb_strlen($candidate) > $max) {
                break;
            }
            $kept = $candidate;
        }
        if ('' !== $kept) {
            return $kept;
        }

        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if (false !== $space && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t.,;:!?-—").'…';
    }
}
