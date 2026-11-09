<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\PlannedMaintenance;
use App\Repository\PlannedMaintenanceRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Resolve a planned task by ULID, owner-gated through its vehicle — a foreign or soft-deleted
 * vehicle's task is simply absent. The single tenancy check for the part subresource and the
 * `remove_planned_part` tool.
 */
final readonly class OwnedPlannedTaskLocator
{
    public function __construct(
        private PlannedMaintenanceRepository $tasks,
    ) {
    }

    public function find(Ulid $id): ?PlannedMaintenance
    {
        return $this->tasks->findOwned($id);
    }
}
