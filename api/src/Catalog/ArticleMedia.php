<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class ArticleMedia
{
    public function __construct(
        public string $source,
        public string $type,
        public string $description,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromPartsApi(array $row): self
    {
        return new self(
            source: trim((string) ($row['ART_MEDIA_SOURCE'] ?? '')),
            type: strtoupper(trim((string) ($row['ART_MEDIA_TYPE'] ?? ''))),
            description: trim((string) ($row['ART_MEDIA_DESCRIPTION'] ?? '')),
        );
    }
}
