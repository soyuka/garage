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
                $merged[$criterion->id ?? $criterion->name][] = $criterion;
            }
        }

        $criteria = $this->attributes;
        foreach ($merged as $same) {
            $criteria[] = ArticleCriterion::merge($same);
        }

        return $criteria;
    }
}
