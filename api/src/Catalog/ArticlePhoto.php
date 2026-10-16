<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class ArticlePhoto
{
    public function __construct(
        public string $contentType,
        public string $body,
    ) {
    }
}
