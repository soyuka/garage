<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * partsapi criteria are per ARTICLE: the union over every vehicle it fits (engine codes, year and VIN
 * breakpoints of all applications). Only what describes the part itself can tell two parts apart for
 * one car, so application-level criteria are denied and everything else must be a measurement (a
 * unit in the name) or a known discrete physical key.
 */
final class FingerprintBuilder
{
    private const DENY = [
        '/^ann[ée]e /u',
        '/^code moteur/u',
        '/^n[°º]? ?de s[ée]rie/u',
        '/^num[ée]ro de ch[âa]ssis/u',
        '/^num[ée]ro tecdoc du moteur/u',
        '/^pour le mod[èe]le/u',
        '/^type de v[ée]hicule/u',
        '/^r[èe]glement europ[ée]en/u',
        "/^r[ée]f[ée]rence de l['’]outil/u",
    ];

    private const DISCRETE = [
        '/^type_de_filtre$/',
        '/^version$/',
        '/^cote_d_assemblage$/',
        '/^position(_|$)/',
        '/^type_de_disque$/',
        '/^ventile$/',
        '/^nombre_de_pistons$/',
    ];

    private const THREAD = '/^(?:taraudage_)?filetage(?:_(\d+))?$/';

    /**
     * The only measured keys that tell two parts apart, by the slug partsapi spells them with. Any other
     * measured key ("diametre_3", "diametre_interieur_2", packaging, weight, torque, valve pressure…) is
     * numbered or filled by each supplier by its own convention, so it is shown but never decides.
     */
    private const SPLITTING_MEASURES = [
        'hauteur' => 'hauteur',
        'diametre_exterieur' => 'diametre_exterieur',
        'diametre' => 'diametre_exterieur',
        'diametre_exterieur_de_la_bague_d_etancheite' => 'diametre_exterieur_de_la_bague_d_etancheite',
        'diametre_interieur_de_la_bague_d_etancheite' => 'diametre_interieur_de_la_bague_d_etancheite',
        'diametre_de_la_bague_detancheite' => 'diametre_interieur_de_la_bague_d_etancheite',
    ];

    /**
     * @param list<ArticleCriterion> $criteria
     */
    public static function fromCriteria(array $criteria): Fingerprint
    {
        $slugger = new AsciiSlugger();
        $entries = [];

        foreach ($criteria as $criterion) {
            $name = mb_strtolower(trim($criterion->name));
            $value = trim($criterion->value);
            if ('' === $name || '' === $value || str_contains($value, ';') || self::denied($name)) {
                continue;
            }

            $unit = null;
            $base = $criterion->name;
            if (false !== $bracket = mb_strpos($criterion->name, '[')) {
                $base = trim(mb_substr($criterion->name, 0, $bracket));
                $unit = mb_strtolower(trim(rtrim(mb_substr($criterion->name, $bracket + 1), ']')));
                // Upstream cuts names at ~45 chars, sometimes right at the bracket: a length with no unit left is mm.
                if ('' === $unit && 1 === preg_match('/^(?:diam|haut|long|larg|[ée]paiss)/iu', $base)) {
                    $unit = 'mm';
                }
            }

            $slug = mb_strtolower($slugger->slug($base, '_')->toString());
            if ('' === $slug) {
                continue;
            }

            $key = self::threadKey($slug);
            if (null !== $key) {
                $entry = new FingerprintEntry($key, $criterion->name, self::thread($value), null, null);
            } elseif (null !== $unit || self::isDiscrete($slug)) {
                $number = preg_match('/^-?\d+(?:[.,]\d+)?$/', $value) ? (float) str_replace(',', '.', $value) : null;
                $key = null !== $unit ? (self::SPLITTING_MEASURES[$slug] ?? $slug) : $slug;
                $entry = new FingerprintEntry(
                    $key,
                    $criterion->name,
                    null === $number ? $value : self::formatNumber($number),
                    $number,
                    '' === $unit ? null : $unit,
                    $number,
                    $number,
                    null !== $unit && !isset(self::SPLITTING_MEASURES[$slug]),
                );
            } else {
                continue;
            }

            $entries[$entry->key] ??= $entry;
        }

        return new Fingerprint($entries);
    }

    private static function denied(string $lowerName): bool
    {
        foreach (self::DENY as $pattern) {
            if (1 === preg_match($pattern, $lowerName)) {
                return true;
            }
        }

        return false;
    }

    private static function isDiscrete(string $slug): bool
    {
        foreach (self::DISCRETE as $pattern) {
            if (1 === preg_match($pattern, $slug)) {
                return true;
            }
        }

        return false;
    }

    private static function threadKey(string $slug): ?string
    {
        if (1 !== preg_match(self::THREAD, $slug, $m)) {
            return null;
        }

        $index = (int) ($m[1] ?? 1);

        return $index > 1 ? 'thread_'.$index : 'thread';
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
