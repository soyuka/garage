<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\PlannedMaintenance;
use App\Mercure\DomainPublisher;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * The one domain call behind dropping a shopping-list line, shared by the REST DELETE on the parts
 * subresource and the `remove_planned_part` MCP tool.
 */
final readonly class PlannedPartRemover
{
    public function __construct(
        private DocumentManager $em,
        private DomainPublisher $publisher,
    ) {
    }

    public function remove(PlannedMaintenance $task, Ulid $partId): bool
    {
        if (!$task->removePart($partId)) {
            return false;
        }

        $this->em->flush();
        $this->publisher->publishVehicle($task->getVehicle());

        return true;
    }
}
