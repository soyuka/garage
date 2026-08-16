<?php

declare(strict_types=1);

namespace App\Mcp;

use App\ApiResource\Article;
use App\Catalog\BrandTier;

/**
 * Projects the articles of one part category into a compact, brand-grouped payload shared
 * by list_articles and get_service_parts. partsapi returns articles fitment-correct but
 * alphabetical by brand and dozens deep (a popular car's oil filter = 220 articles / 126
 * brands), so a blind slice surfaced only no-name leading-A brands and buried MANN/BOSCH.
 *
 * Articles are grouped by brand, ranked by BrandTier (OE-grade first), and the top
 * MAX_BRANDS brands are kept — each carrying ALL its fitting refs (multi-variant brands
 * like PURFLUX keep both LS357 + LS1176). totalArticles/totalBrands keep the full counts.
 * Every returned article is already fitment-scoped by partsapi, so any surfaced brand fits.
 */
final class ArticleGroup
{
    public const MAX_BRANDS = 6;

    /**
     * @param list<Article> $articles
     * @param list<string>  $brands   restrict to these brands; the ranked cap does NOT apply, and any
     *                                asked brand with no fitting article comes back under `missing`
     *
     * @return array<string, mixed>
     */
    public static function of(int $categoryId, array $articles, int $maxBrands = self::MAX_BRANDS, array $brands = []): array
    {
        /** @var array<string, list<Article>> $byBrand */
        $byBrand = [];
        foreach ($articles as $article) {
            $byBrand[$article->brand][] = $article;
        }

        $all = array_keys($byBrand);
        usort($all, static fn (string $a, string $b): int => BrandTier::rank($a) <=> BrandTier::rank($b) ?: strcasecmp($a, $b));

        $totalBrands = \count($all);

        // A filtered answer is COMPLETE for what was asked, so it ignores the ranked cap — the cap exists
        // to stop an unasked list burying recognizable brands, not to hide a brand the operator named.
        // Naming the misses is the honesty seam: "we do not carry it for this part" and "you asked wrong"
        // must not look alike (silence here is what let the agent invent a comparison it never fetched).
        $missing = [];
        if ([] !== $brands) {
            $fitting = [];
            foreach ($brands as $asked) {
                $matched = array_values(array_filter($all, static fn (string $b): bool => self::sameBrand($b, $asked)));
                if ([] === $matched) {
                    $missing[] = $asked;
                    continue;
                }
                foreach ($matched as $brand) {
                    $fitting[$brand] = true;
                }
            }
            $all = array_values(array_filter($all, static fn (string $b): bool => isset($fitting[$b])));
        }

        $shown = [] !== $brands ? $all : \array_slice($all, 0, $maxBrands);

        $groups = [];
        foreach ($shown as $brand) {
            $groups[] = [
                'brand' => $brand,
                'tier' => BrandTier::classify($brand),
                'articles' => array_map(
                    static fn (Article $a): array => ['id' => $a->id, 'number' => $a->number],
                    $byBrand[$brand],
                ),
            ];
        }

        return [
            'categoryId' => $categoryId,
            'totalArticles' => \count($articles),
            'totalBrands' => $totalBrands,
            'truncated' => [] === $brands && $totalBrands > $maxBrands,
            'brands' => $groups,
        ] + ([] === $brands ? [] : ['missing' => $missing]);
    }

    /** Brand names compare on letters and digits only: "mann filter" is "MANN-FILTER". */
    private static function sameBrand(string $a, string $b): bool
    {
        $strip = static fn (string $s): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s));

        return $strip($a) === $strip($b);
    }
}
