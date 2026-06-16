<?php

declare(strict_types=1);

namespace App\Mcp;

use App\ApiResource\Article;

/**
 * Projects the articles of one part category into a capped, envelope-free group payload
 * shared by list_articles and get_service_parts. A category can carry dozens of aftermarket
 * brands; the LLM needs a handful, so articles are capped (totalArticles keeps the full count).
 */
final class ArticleGroup
{
    /**
     * @param list<Article> $articles
     *
     * @return array<string, mixed>
     */
    public static function of(int $categoryId, array $articles, int $limit = CappedResult::DEFAULT_LIMIT): array
    {
        $total = \count($articles);
        $shown = \array_slice($articles, 0, $limit);

        return [
            'categoryId' => $categoryId,
            'totalArticles' => $total,
            'truncated' => $total > $limit,
            'articles' => array_map(static fn (Article $article): array => (array) $article, $shown),
        ];
    }
}
