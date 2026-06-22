<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * The composed health read-model: a status, an optional 0–100 score (null when Unknown), and the
 * breakdown it was derived from so the dashboard can explain the badge ("1 overdue, 2 gaps").
 */
final readonly class VehicleHealth
{
    public function __construct(
        public HealthStatus $status,
        public ?int $score,
        public int $overdue,
        public int $dueSoon,
        public int $tracked,
        public int $gaps,
    ) {
    }
}
