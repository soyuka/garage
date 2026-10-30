<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * An article's criteria on ONE vehicle: its own attributes, and the qualifiers of its link to that vehicle. A link
 * can carry several qualifier sets: they are alternatives, each holding as a whole.
 */
final readonly class VehicleLinkCriteria
{
    /**
     * @param list<ArticleCriterion>       $attributes
     * @param list<list<ArticleCriterion>> $qualifierSets
     */
    public function __construct(
        public array $attributes = [],
        public array $qualifierSets = [],
    ) {
    }

    /**
     * The attributes, then the qualifiers merged per criterion with `; ` across the sets, as
     * {@see PartsCatalog::articleCriteria()} renders them.
     *
     * @return list<ArticleCriterion>
     */
    public function criteria(): array
    {
        $merged = [];
        foreach ($this->qualifierSets as $set) {
            foreach ($set as $criterion) {
                $key = $criterion->id ?? $criterion->name;
                $merged[$key]['name'] ??= $criterion->name;
                $merged[$key]['id'] ??= $criterion->id;
                $merged[$key]['values'][$criterion->value] = true;
            }
        }

        $criteria = $this->attributes;
        foreach ($merged as $entry) {
            $values = array_map('strval', array_keys($entry['values']));
            sort($values, \SORT_NATURAL);
            $criteria[] = new ArticleCriterion($entry['name'], implode('; ', $values), $entry['id']);
        }

        return $criteria;
    }
}
