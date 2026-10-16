<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\ArticlePhoto;
use App\Catalog\ArticlePhotoStore;

final class InMemoryArticlePhotoStore implements ArticlePhotoStore
{
    /** @var array<string, ArticlePhoto> */
    private array $photos = [];

    public function find(string $url): ?ArticlePhoto
    {
        return $this->photos[$url] ?? null;
    }

    public function save(string $url, ArticlePhoto $photo): void
    {
        $this->photos[$url] = $photo;
    }
}
