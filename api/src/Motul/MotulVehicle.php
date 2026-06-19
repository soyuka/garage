<?php

declare(strict_types=1);

namespace App\Motul;

/**
 * A candidate vehicle from Motul's `search-vehicle` endpoint. `id` keys the recommendations page
 * (https://www.motul.com/<locale>/lubricants/recommendations/<id>); the year range and engine
 * `type` are what we match our own vehicle against to pick the right variant.
 */
final readonly class MotulVehicle
{
    public function __construct(
        public string $id,
        public string $brand,
        public string $model,
        public string $type,
        public ?int $startYear,
        public ?int $endYear,
        public ?string $modelCode,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? ''),
            brand: (string) ($row['brand'] ?? ''),
            model: (string) ($row['model'] ?? ''),
            type: (string) ($row['type'] ?? ''),
            startYear: isset($row['startYear']) && '' !== $row['startYear'] ? (int) $row['startYear'] : null,
            endYear: isset($row['endYear']) && '' !== $row['endYear'] ? (int) $row['endYear'] : null,
            modelCode: isset($row['modelCode']) && '' !== $row['modelCode'] ? (string) $row['modelCode'] : null,
        );
    }

    public function coversYear(int $year): bool
    {
        return (null === $this->startYear || $year >= $this->startYear)
            && (null === $this->endYear || $year <= $this->endYear);
    }
}
