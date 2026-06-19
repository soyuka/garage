<?php

declare(strict_types=1);

namespace App\Motul;

/**
 * A fluid category on a Motul recommendation page (Engine, "Transaxle, manual", "Power steering"…)
 * with its capacities, the ACEA/API quality standards of the advised products, and one
 * {@see MotulConditionGrade} per usage×mileage cell.
 */
final readonly class MotulCategory
{
    /**
     * @param list<string>              $capacities    raw capacity labels, e.g. ["Capacity 4,6 liter"]
     * @param list<string>              $aceaStandards quality standards across the advised products
     * @param list<MotulConditionGrade> $conditions    one cell per usage×mileage combination
     */
    public function __construct(
        public string $name,
        public array $capacities,
        public array $aceaStandards,
        public array $conditions,
    ) {
    }

    public function isEngine(): bool
    {
        return 0 === strcasecmp($this->name, 'Engine');
    }

    /**
     * Grades across every cell, deduped — the answer when the grade does not vary by condition.
     *
     * @return list<string>
     */
    public function viscosities(): array
    {
        $all = [];
        foreach ($this->conditions as $cell) {
            foreach ($cell->viscosities as $v) {
                $all[$v] = true;
            }
        }

        return array_keys($all);
    }

    /**
     * True when different cells advise different grade sets — then the caller must surface the
     * per-condition breakdown rather than a single grade.
     */
    public function gradeVariesByCondition(): bool
    {
        $sets = [];
        foreach ($this->conditions as $cell) {
            $set = $cell->viscosities;
            sort($set);
            $sets[implode(',', $set)] = true;
        }

        return \count($sets) > 1;
    }

    public function intervalForUsage(string $usage): ?string
    {
        foreach ($this->conditions as $cell) {
            if (0 === strcasecmp($cell->usage, $usage) && null !== $cell->interval) {
                return $cell->interval;
            }
        }

        return null;
    }
}
