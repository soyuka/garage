<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class ArticleCriterion
{
    /**
     * @param ?string $unit          the criterion's unit in the caller's language, for display
     * @param ?string $canonicalName the criterion's French name, the stable identifier its fingerprint key is spelled from
     * @param ?string $code          the value as stored (key code, number with a dot), the same whatever the caller's language
     */
    public function __construct(
        public string $name,
        public string $value,
        public ?int $id = null,
        public ?string $unit = null,
        public ?string $canonicalName = null,
        public ?string $code = null,
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

    /**
     * One criterion carrying every value the same criterion takes across several rows, joined with `; `.
     *
     * @param non-empty-list<self> $same
     */
    public static function merge(array $same): self
    {
        $values = array_values(array_unique(array_map(static fn (self $c): string => $c->value, $same)));
        sort($values, \SORT_NATURAL);
        $codes = array_values(array_unique(array_map(static fn (self $c): string => $c->code ?? $c->value, $same)));
        sort($codes, \SORT_NATURAL);
        $first = $same[0];

        return new self($first->name, implode('; ', $values), $first->id, $first->unit, $first->canonicalName, implode('; ', $codes));
    }
}
