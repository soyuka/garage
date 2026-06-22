<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * A vehicle's current reading lifted into the maintenance unit vocabulary (engine `hours`, not the
 * garage-side `h`) so the forecast can compare it against a {@see \App\Entity\ServiceInterval}'s
 * usage cadence on the same axis.
 */
final readonly class CurrentUsage
{
    public function __construct(
        public int $value,
        public UsageUnit $unit,
    ) {
    }
}
