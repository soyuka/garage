<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Maintenance\MaintenanceType;
use App\Maintenance\PlannedMaintenanceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlannedMaintenance>
 */
final class PlannedMaintenanceRepository extends ServiceEntityRepository
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
        return $this->createQueryBuilder('p')
            ->andWhere('p.vehicle = :vehicle')
            // Bind the ULID explicitly — same reason as MaintenanceRecordRepository::history().
            ->setParameter('vehicle', $vehicle->getId(), 'ulid')
            ->andWhere('p.status = :open')
            ->setParameter('open', PlannedMaintenanceStatus::Open)
            ->orderBy('p.createdAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The single open task for a (vehicle, type, label) triple, or null — the dedup probe the
     * planner uses to upsert rather than spawn a duplicate on a re-run. A null label only matches
     * a null label (a labelled "other" task is distinct from an unlabelled one).
     */
    public function findOpen(Vehicle $vehicle, MaintenanceType $type, ?string $label): ?PlannedMaintenance
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle->getId(), 'ulid')
            ->andWhere('p.type = :type')
            ->setParameter('type', $type)
            ->andWhere('p.status = :open')
            ->setParameter('open', PlannedMaintenanceStatus::Open)
            ->setMaxResults(1);

        if (null === $label) {
            $qb->andWhere('p.label IS NULL');
        } else {
            $qb->andWhere('p.label = :label')->setParameter('label', $label);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Every open task of a type on a vehicle — what log_maintenance auto-closes when that service
     * is performed.
     *
     * @return list<PlannedMaintenance>
     */
    public function openOfType(Vehicle $vehicle, MaintenanceType $type): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle->getId(), 'ulid')
            ->andWhere('p.type = :type')
            ->setParameter('type', $type)
            ->andWhere('p.status = :open')
            ->setParameter('open', PlannedMaintenanceStatus::Open)
            ->getQuery()
            ->getResult();
    }
}
