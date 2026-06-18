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
     * The open checklist for a vehicle, oldest planned first.
     *
     * @return list<PlannedMaintenance>
     */
    public function openForVehicle(Vehicle $vehicle): array
    {
        return array_values($this->createQueryBuilder()
            ->field('vehicle')->references($vehicle)
            ->field('status')->equals(PlannedMaintenanceStatus::Open->value)
            ->sort('createdAt', 'asc')
            ->sort('id', 'asc')
            ->getQuery()
            ->execute()
            ->toArray());
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
