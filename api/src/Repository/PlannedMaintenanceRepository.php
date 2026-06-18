<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Maintenance\PlannedMaintenanceStatus;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

/**
 * @extends ServiceDocumentRepository<PlannedMaintenance>
 */
final class PlannedMaintenanceRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlannedMaintenance::class);
    }

    /**
     * The actionable checklist for a vehicle: Open AND Done tasks (Dismissed dropped), open first
     * then done, oldest planned first within each. Done tasks stay on the list so the dashboard can
     * render them ticked-off/greyed instead of vanishing. KNOWN spec carriers (untargeted, partless,
     * e.g. the catalog spec sheet) are excluded — they feed the facts projection, not the to-do list.
     *
     * @return list<PlannedMaintenance>
     */
    public function checklistForVehicle(Vehicle $vehicle): array
    {
        $tasks = $this->createQueryBuilder()
            ->field('vehicle')->references($vehicle)
            ->field('status')->in([PlannedMaintenanceStatus::Open->value, PlannedMaintenanceStatus::Done->value])
            ->sort('createdAt', 'asc')
            ->sort('id', 'asc')
            ->getQuery()
            ->execute()
            ->toArray();

        $actionable = array_values(array_filter($tasks, static fn (PlannedMaintenance $t): bool => $t->isActionable()));
        // Open before done, preserving the createdAt order the query already applied within each group.
        usort($actionable, static fn (PlannedMaintenance $a, PlannedMaintenance $b): int => (int) !$a->isOpen() <=> (int) !$b->isOpen());

        return $actionable;
    }

    /**
     * The single open task for a (vehicle, type, label) triple, or null — the dedup probe the
     * planner uses to upsert rather than spawn a duplicate on a re-run. A null label only matches
     * a null label (a labelled "other" task is distinct from an unlabelled one).
     */
    public function findOpen(Vehicle $vehicle, string $type, ?string $label): ?PlannedMaintenance
    {
        $qb = $this->createQueryBuilder()
            ->field('vehicle')->references($vehicle)
            ->field('type')->equals($type)
            ->field('status')->equals(PlannedMaintenanceStatus::Open->value)
            ->field('label')->equals($label)
            ->limit(1);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Every open task of a type on a vehicle — what log_maintenance auto-closes when that service
     * is performed.
     *
     * @return list<PlannedMaintenance>
     */
    public function openOfType(Vehicle $vehicle, string $type): array
    {
        return array_values($this->createQueryBuilder()
            ->field('vehicle')->references($vehicle)
            ->field('type')->equals($type)
            ->field('status')->equals(PlannedMaintenanceStatus::Open->value)
            ->getQuery()
            ->execute()
            ->toArray());
    }
}
