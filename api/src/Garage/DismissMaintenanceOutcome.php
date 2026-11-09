<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;

/**
 * Result of a dismiss_maintenance attempt — the soft-remove of a checklist task:
 *  - dismissed:     `task` is the open task that was flipped to Dismissed (kept in the DB, dropped
 *                   off the live checklist).
 *  - ambiguous:     no exact identity match, but `candidates` are stored vehicles that may be the
 *                   same one — the caller reconciles (re-call with a vehicleId, or confirmNew).
 *  - notFound:      a vehicleId was given that resolves to no row.
 *  - taskNotFound:  the vehicle resolved, but it carries no open task for that (type, label) — there
 *                   is nothing to remove.
 *
 * Mirrors {@see PlanMaintenanceOutcome}; the extra taskNotFound state is what plan (an upsert that
 * creates on miss) has no need for but dismiss (which can only act on an existing task) does.
 */
final readonly class DismissMaintenanceOutcome implements WriteOutcome
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

    public static function dismissed(PlannedMaintenance $task): self
    {
        return new self('dismissed', task: $task);
    }

    /**
     * The task of the success outcome; the caller has already handled every other status.
     */
    public function dismissedTask(): PlannedMaintenance
    {
        return $this->task ?? throw new \LogicException(\sprintf('A "%s" outcome carries no task.', $this->status));
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

    public static function taskNotFound(): self
    {
        return new self('task_not_found');
    }
}
