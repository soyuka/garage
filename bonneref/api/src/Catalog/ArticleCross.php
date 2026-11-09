<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class ArticleCross
{
    public function __construct(
        public string $brand,
        public string $number,
        public ?int $artId,
        public string $productName,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromCatalogRow(array $row): self
    {
        return new self(
            brand: trim((string) ($row['ART_BRAND'] ?? '')),
            number: trim((string) ($row['ART_DISPLAY_NR'] ?? '')),
            artId: isset($row['ART_ID']) ? (int) $row['ART_ID'] : null,
            productName: trim((string) ($row['ART_PRODUCT_NAME'] ?? '')),
        );
    }
}
