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
class PlannedMaintenanceRepository extends ServiceDocumentRepository
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
     * With $includeDismissed the soft-removed tasks ride along too (the dashboard's "see all" view),
     * sorted last so they sit beneath the live list — a dismiss is recoverable, never a hard delete.
     *
     * @return list<PlannedMaintenance>
     */
    public function checklistForVehicle(Vehicle $vehicle, bool $includeDismissed = false): array
    {
        $statuses = [PlannedMaintenanceStatus::Open->value, PlannedMaintenanceStatus::Done->value];
        if ($includeDismissed) {
            $statuses[] = PlannedMaintenanceStatus::Dismissed->value;
        }

        $tasks = $this->createQueryBuilder()
            ->field('vehicle')->references($vehicle)
            ->field('status')->in($statuses)
            // Archived (filed into the past-maintenance log) tasks leave the current checklist. The
            // null match also covers legacy docs that predate the field (a missing key reads as null).
            ->field('archivedAt')->equals(null)
            ->sort('createdAt', 'asc')
            ->sort('id', 'asc')
            ->getQuery()
            ->getIterator()
            ->toArray();

        $actionable = array_values(array_filter($tasks, static fn (PlannedMaintenance $t): bool => $t->isActionable()));
        // Open, then done, then dismissed — preserving the createdAt order the query already applied
        // within each group. A stable sort keeps that intra-group order.
        usort($actionable, static fn (PlannedMaintenance $a, PlannedMaintenance $b): int => self::statusRank($a) <=> self::statusRank($b));

        return $actionable;
    }

    private static function statusRank(PlannedMaintenance $task): int
    {
        return match ($task->getStatus()) {
            PlannedMaintenanceStatus::Open => 0,
            PlannedMaintenanceStatus::Done => 1,
            PlannedMaintenanceStatus::Dismissed => 2,
        };
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
            ->getIterator()
            ->toArray());
    }

    /**
     * Done-but-not-yet-filed tasks for a vehicle — the batch the "Archiver" action transforms into
     * past-maintenance records. Dismissed tasks (discarded, never performed) are deliberately out:
     * archiving credits done work, never resurrects abandoned plans. The null match covers legacy
     * docs predating the field.
     *
     * @return list<PlannedMaintenance>
     */
    public function completedUnarchived(Vehicle $vehicle): array
    {
        return array_values($this->createQueryBuilder()
            ->field('vehicle')->references($vehicle)
            ->field('status')->equals(PlannedMaintenanceStatus::Done->value)
            ->field('archivedAt')->equals(null)
            ->sort('completedAt', 'asc')
            ->sort('id', 'asc')
            ->getQuery()
            ->getIterator()
            ->toArray());
    }
}
