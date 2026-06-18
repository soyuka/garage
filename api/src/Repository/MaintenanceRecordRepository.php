<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

/**
 * @extends ServiceDocumentRepository<MaintenanceRecord>
 */
final class MaintenanceRecordRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaintenanceRecord::class);
    }

    /**
     * Service history for a vehicle, newest event first (tie-break on id so same-day events
     * keep insertion order — the ULID _id is time-ordered). Optionally narrowed to a single type.
     *
     * @return list<MaintenanceRecord>
     */
    public function history(Vehicle $vehicle, ?string $type = null, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder()
            ->field('vehicle')->references($vehicle)
            ->sort('performedAt', 'desc')
            ->sort('id', 'desc');

        if (null !== $type) {
            $qb->field('type')->equals($type);
        }

        if (null !== $limit) {
            $qb->limit($limit);
        }

        return array_values($qb->getQuery()->execute()->toArray());
    }
}
