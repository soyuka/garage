<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceDocumentRepository<MaintenanceRecord>
 */
final class MaintenanceRecordRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry, private readonly VehicleRepository $vehicles)
    {
        parent::__construct($registry, MaintenanceRecord::class);
    }

    /**
     * The record under this id when its vehicle is the current owner's and not deleted, else null.
     * The owner lives on the vehicle, so the floor is {@see VehicleRepository::findOwned()}.
     */
    public function findOwned(Ulid|string $id): ?MaintenanceRecord
    {
        $record = $this->find((string) $id);

        return null !== $record && null !== $this->vehicles->findOwned((string) $record->getVehicle()->getId()) ? $record : null;
    }

    /**
     * Service history for a vehicle, newest event first (tie-break on id so same-day events
     * keep insertion order — the ULID _id is time-ordered). Optionally narrowed to visits that
     * include a given service type among their line-items (matches on the items.type multikey path).
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
            $qb->field('items.type')->equals($type);
        }

        if (null !== $limit) {
            $qb->limit($limit);
        }

        return QueryResults::listOf(MaintenanceRecord::class, $qb->getQuery()->getIterator());
    }
}
