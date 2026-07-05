<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;

/**
 * Result of a plan_maintenance attempt:
 *  - planned:   `task` is the open task that was created or updated in place (upsert).
 *  - ambiguous: no exact identity match, but `candidates` are stored vehicles that may be the same
 *               one — the caller reconciles (re-call with a vehicleId, or confirmNew).
 *  - notFound:  a vehicleId was given that resolves to no row.
 *
 * Mirrors {@see UpdateVehicleOutcome}; no "refused" — planning carries no fact-precedence rule.
 */
final readonly class PlanMaintenanceOutcome implements WriteOutcome
{
    use WriteOutcomeToolResult;

    /**
     * @param list<Vehicle> $candidates
     */
    private function __construct(
        public string $status,
        public ?PlannedMaintenance $task = null,
        public array $candidates = [],
        public ?string $vehicleId = null,
    ) {
    }

    public static function planned(PlannedMaintenance $task): self
    {
        return new self('planned', task: $task);
    }

    /**
     * @param list<Vehicle> $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self('ambiguous', candidates: $candidates);
    }

    public static function notFound(?string $vehicleId): self
    {
        return new self('not_found', vehicleId: $vehicleId);
    }
}
