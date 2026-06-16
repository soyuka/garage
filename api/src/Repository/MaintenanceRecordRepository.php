<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Maintenance\MaintenanceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MaintenanceRecord>
 */
final class MaintenanceRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaintenanceRecord::class);
    }

    /**
     * Service history for a vehicle, newest event first (tie-break on id so same-day events
     * keep insertion order). Optionally narrowed to a single type.
     *
     * @return list<MaintenanceRecord>
     */
    public function history(Vehicle $vehicle, ?MaintenanceType $type = null, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.vehicle = :vehicle')
            // Bind the ULID explicitly: passing the entity lets Doctrine stringify the id to
            // base32, which a uuid column rejects. The 'ulid' type emits the RFC 4122 form.
            ->setParameter('vehicle', $vehicle->getId(), 'ulid')
            ->orderBy('r.performedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        if (null !== $type) {
            $qb->andWhere('r.type = :type')->setParameter('type', $type);
        }

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }
}
