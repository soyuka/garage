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
     *
     * @return array<string, mixed>
     */
    public static function of(int $categoryId, array $articles, int $maxBrands = self::MAX_BRANDS): array
    {
        /** @var array<string, list<Article>> $byBrand */
        $byBrand = [];
        foreach ($articles as $article) {
            $byBrand[$article->brand][] = $article;
        }

        $brands = array_keys($byBrand);
        usort($brands, static fn (string $a, string $b): int => BrandTier::rank($a) <=> BrandTier::rank($b) ?: strcasecmp($a, $b));

        $totalBrands = \count($brands);
        $shown = \array_slice($brands, 0, $maxBrands);

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
            'truncated' => $totalBrands > $maxBrands,
            'brands' => $groups,
        ];
    }
}
