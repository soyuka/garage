<?php

declare(strict_types=1);

namespace App\Transcription;

/**
 * The vendor's constraints on a bias list, enforced before anything is sent.
 *
 * They are HARD limits at ElevenLabs — a term too long, too wordy, or carrying one of the forbidden
 * characters is a 400 for the whole request — so this is not tidying, it is what keeps one oddly named
 * vehicle in somebody's garage from costing them the ability to dictate at all. Pure and static: the
 * rules are testable without a database ({@see \App\Tests\Unit\Transcription\KeytermsTest}), and
 * {@see GarageVocabulary} is left with nothing but the read.
 */
final class Keyterms
{
    /**
     * Where the list is cut. NOT the vendor's ceiling, which is 1 000 (checked against their reference
     * 2026-09-11) — it is a BILLING cliff: past 100 terms ElevenLabs applies a 20-second minimum
     * billable duration to the request, so the 101st term silently triples the cost of a 6-second
     * instruction. A hundred names is already more garage than the bias can usefully weigh.
     */
    public const int MAX_TERMS = 100;

    /** Per term, at the vendor: 50 characters and 5 words. */
    private const int MAX_CHARS = 50;
    private const int MAX_WORDS = 5;

    /** Refused by the vendor inside a term. Stripped rather than dropping the term (or the request). */
    private const string FORBIDDEN = '<>{}[]\\';

    /**
     * @param list<string> $terms in priority order — the caller's OWN vocabulary first, because the
     *                            cap keeps the head of the list and a large garage must lose the
     *                            generic filler rather than the names of its own vehicles
     *
     * @return list<string> at most {@see MAX_TERMS}, deduped case-insensitively, vendor-safe
     */
    public static function normalize(array $terms): array
    {
        $kept = [];
        $seen = [];

        foreach ($terms as $term) {
            $clean = self::clean($term);
            if (null === $clean) {
                continue;
            }

            // "MAZDA" and "Mazda" are one term to a transcriber, and one slot out of a hundred.
            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $clean;

            if (self::MAX_TERMS === \count($kept)) {
                break;
            }
        }

        return $kept;
    }

    /** One term, vendor-safe — or null when nothing usable is left of it. */
    private static function clean(string $term): ?string
    {
        $clean = trim(str_replace(str_split(self::FORBIDDEN), '', $term));
        // A model name typed with two spaces is not a different term from the same name with one.
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';

        if ('' === $clean || mb_strlen($clean) > self::MAX_CHARS) {
            return null;
        }

        // A term is a name, not a sentence.
        return \count(explode(' ', $clean)) > self::MAX_WORDS ? null : $clean;
    }
}
