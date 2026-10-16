<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Where ArticlePhotos keeps every downloaded catalogue photo, by its source URL.
 */
interface ArticlePhotoStore
{
    public function find(string $url): ?ArticlePhoto;

    public function save(string $url, ArticlePhoto $photo): void;
}
