<?php

declare(strict_types=1);

namespace App\Motul;

/**
 * One usage×mileage cell of a Motul recommendation: the grade(s) Motul advises for that operating
 * condition, plus the drain interval. Motul segments by usage (Normal/Severe) and mileage band
 * (±150 000 km) because a high-mileage or hard-driven engine can take a different grade/interval.
 */
final readonly class MotulConditionGrade
{
    /**
     * @param list<string> $viscosities SAE grades advised for this cell (e.g. ["5W-30"])
     */
    public function __construct(
        public string $usage,
        public string $mileage,
        public ?string $interval,
        public array $viscosities,
    ) {
    }
}
