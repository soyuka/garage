<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class ArticleCriterion
{
    public function __construct(
        public string $name,
        public string $value,
        public ?int $id = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromCatalogRow(array $row): self
    {
        return new self(
            name: trim((string) ($row['CRITERIA_NAME'] ?? '')),
            value: trim((string) ($row['CRITERIA_VALUE'] ?? '')),
        );
    }
}
