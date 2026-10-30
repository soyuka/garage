<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Curated quality ranking for spare-part brands. partsapi `getArticles` carries no
 * price/popularity signal — only the brand name — so "is this an OE-grade brand" is
 * hand-curated knowledge, not derivable from the response. Used to surface recognizable
 * brands (MANN, MAHLE, BOSCH, PURFLUX…) instead of the alphabetically-first no-names.
 *
 * The tier-1/tier-2 lists are ORDERED by preference: position within a tier is the
 * priority used for sorting (so MANN-FILTER outranks a later tier-1 brand). Everything
 * not listed is tier 3.
 */
final class BrandTier
{
    private const TIER_STEP = 1000;

    /** Tier 1 — OE / OEM-grade, ordered by recognizability + variety. */
    private const TIER1 = [
        'MANN-FILTER', 'MAHLE', 'BOSCH', 'PURFLUX', 'HENGST FILTER', 'VALEO',
        'FRAM', 'FILTRON', 'KNECHT', 'CHAMPION', 'DENSO', 'DELPHI', 'UFI', 'SOGEFI',
    ];

    /** Tier 2 — quality aftermarket. */
    private const TIER2 = [
        'FEBI BILSTEIN', 'BLUE PRINT', 'MEYLE', 'RIDEX', 'MAGNETI MARELLI',
        'KOLBENSCHMIDT', 'DENCKERMANN', 'NPS', 'JAPANPARTS', 'ASHIKA', 'COMLINE',
        'JC PREMIUM', 'KAMOKA', 'MASTER-SPORT GERMANY', 'HERTH+BUSS JAKOPARTS',
        'MECAFILTER', 'METAL LEVE', 'MISFAT', 'GUD', 'NIPPARTS',
    ];

    /**
     * Lower = better. tier-1 brands occupy [0, STEP), tier-2 [STEP, 2*STEP),
     * everything else 3*STEP. Within a tier, the curated list order is the priority.
     */
    public static function rank(string $brand): int
    {
        return self::rankMap()[self::normalize($brand)] ?? 2 * self::TIER_STEP;
    }

    public static function classify(string $brand): int
    {
        return intdiv(self::rank($brand), self::TIER_STEP) + 1;
    }

    /**
     * Rank the brands someone asked about, best first — the curated opinion, made answerable.
     *
     * `known` is the honesty seam: tier 3 means "not on our list", NOT "bad". Reporting an unlisted
     * brand as a quality verdict would invent a claim, which is precisely what this replaces — before
     * it existed the agent compared brands from its weights and justified the answer with availability
     * data we do not hold.
     *
     * @param list<string> $brands
     *
     * @return list<array{brand: string, tier: int, known: bool}> the brand echoed as it was spelled
     */
    public static function rankAll(array $brands): array
    {
        $ranked = array_map(
            static fn (string $brand): array => [
                'brand' => $brand,
                'tier' => self::classify($brand),
                'known' => \array_key_exists(self::normalize($brand), self::rankMap()),
            ],
            array_values($brands),
        );

        usort(
            $ranked,
            static fn (array $a, array $b): int => self::rank($a['brand']) <=> self::rank($b['brand'])
                ?: strcasecmp($a['brand'], $b['brand']),
        );

        return $ranked;
    }

    /**
     * @return array<string, int>
     */
    private static function rankMap(): array
    {
        static $map = null;
        if (null !== $map) {
            return $map;
        }

        $map = [];
        foreach (self::TIER1 as $i => $brand) {
            $map[self::normalize($brand)] = $i;
        }
        foreach (self::TIER2 as $i => $brand) {
            $map[self::normalize($brand)] = self::TIER_STEP + $i;
        }

        return $map;
    }

    private static function normalize(string $brand): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $brand));
    }
}
