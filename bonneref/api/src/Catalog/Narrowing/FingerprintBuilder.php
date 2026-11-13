<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * A part's criteria are per ARTICLE: the union over every vehicle it fits (engine codes, year and VIN breakpoints of
 * all applications). Only what describes the part itself can tell two parts apart for one car, so application-level
 * criteria are denied and everything else must be a measurement (a unit) or a known discrete physical key.
 *
 * Criteria are classified by TecDoc criterion id ({@see NarrowingCriteria}), never by name: the name is the caller's
 * language and only labels the entry.
 */
final class FingerprintBuilder
{
    /**
     * @param list<ArticleCriterion> $criteria
     */
    public static function fromCriteria(array $criteria): Fingerprint
    {
        $slugger = new AsciiSlugger();
        $entries = [];

        foreach ($criteria as $criterion) {
            $id = $criterion->id;
            $value = trim($criterion->value);
            $code = $criterion->code ?? $value;
            if (null === $id || '' === $value || str_contains($code, ';') || \in_array($id, NarrowingCriteria::APPLICATION_ONLY, true)) {
                continue;
            }

            $unit = null === $criterion->unit ? null : mb_strtolower($criterion->unit);

            if (isset(NarrowingCriteria::THREADS[$id])) {
                $index = NarrowingCriteria::THREADS[$id];
                $entry = new FingerprintEntry($index > 1 ? 'thread_'.$index : 'thread', $criterion->name, self::thread($value), null, null);
            } elseif (null !== $unit || \in_array($id, NarrowingCriteria::DISCRETE, true)) {
                // @mago-expect analysis:invalid-type-cast the pattern only lets a decimal number through
                $number = preg_match('/^-?\d+(?:[.,]\d+)?$/', $value) ? (float) str_replace(',', '.', $value) : null;
                $entry = new FingerprintEntry(
                    NarrowingCriteria::MEASURES[$id] ?? self::key($criterion, $slugger),
                    $criterion->name,
                    null === $number ? $value : self::formatNumber($number),
                    $number,
                    $unit,
                    $number,
                    $number,
                    null !== $unit && !isset(NarrowingCriteria::MEASURES[$id]),
                    $code,
                );
            } else {
                continue;
            }

            $entries[$entry->key] ??= $entry;
        }

        return new Fingerprint($entries);
    }

    /** The slug of the French name before its unit, the same for every caller: what the clients key a dimension on. */
    private static function key(ArticleCriterion $criterion, AsciiSlugger $slugger): string
    {
        $name = $criterion->canonicalName ?? $criterion->name;
        $bracket = mb_strpos($name, '[');

        return mb_strtolower($slugger->slug(trim(false === $bracket ? $name : mb_substr($name, 0, $bracket)), '_')->toString());
    }

    private static function thread(string $value): string
    {
        if (1 === preg_match('/M\s*(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)/iu', $value, $m)) {
            return 'M'.str_replace(',', '.', $m[1]).'x'.str_replace(',', '.', $m[2]);
        }

        // Denominators are the fractional-inch sizes, which keeps "3/416" reading as 3/4 then 16.
        if (1 === preg_match('~(\d+/(?:64|32|16|8|4|2))\D*?-?(\d+)~', $value, $m)) {
            return $m[1].'-'.$m[2];
        }

        return mb_strtolower(trim($value));
    }

    private static function formatNumber(float $number): string
    {
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }
}
