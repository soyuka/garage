<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\PlannedMaintenance;
use App\Repository\PlannedMaintenanceRepository;
use App\Security\OwnerContext;
use Symfony\Component\Uid\Ulid;

/**
 * Resolve a planned task by ULID, owner-gated through its vehicle — a foreign or soft-deleted
 * vehicle's task is simply absent. The single tenancy check for the part subresource and the
 * `remove_planned_part` tool.
 */
final class OwnedPlannedTaskLocator
{
    public function __construct(
        private readonly PlannedMaintenanceRepository $tasks,
        private readonly OwnerContext $owner,
    ) {
    }

    public function find(Ulid $id): ?PlannedMaintenance
    {
        $task = $this->tasks->find($id);
        if (null === $task || $task->getVehicle()->getOwner() !== $this->owner->current() || $task->getVehicle()->isDeleted()) {
            return null;
        }

        return $task;
    }
}
