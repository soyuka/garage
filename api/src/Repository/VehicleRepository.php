<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vehicle>
 */
final class VehicleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    /**
     * Case-insensitive identity match on make + model + vehicleType, narrowed by year when
     * given. vehicleType is part of identity (TODO.md data model): a `groundscare` TORO and a
     * `cv` row sharing make+model are distinct vehicles and must not bind facts onto each other.
     * Phase 1 looks only at the shared garage (owner IS NULL).
     */
    public function findByIdentity(string $make, string $model, VehicleType $vehicleType, ?int $year = null): ?Vehicle
    {
        $qb = $this->createQueryBuilder('v')
            ->andWhere('LOWER(v.make) = LOWER(:make)')
            ->andWhere('LOWER(v.model) = LOWER(:model)')
            ->andWhere('v.vehicleType = :vehicleType')
            ->andWhere('v.owner IS NULL')
            ->setParameter('make', $make)
            ->setParameter('model', $model)
            ->setParameter('vehicleType', $vehicleType)
            ->setMaxResults(1);

        if (null !== $year) {
            $qb->andWhere('v.year = :year')->setParameter('year', $year);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Surface stored vehicles that may be the same one as an incoming write but did NOT match
     * on exact identity — the input for the agent's reconcile-or-create decision. Within the
     * same segment (vehicleType), a candidate is anything sharing the model label (catches a
     * rebadged machine: TORO vs Wheel Horse 246-H) or a strong stable id (kType/plate/vin,
     * catches the same TecDoc vehicle stored under two model strings). Deliberately broad: the
     * agent judges, these ids only rank what it confronts. Phase 1 looks at the shared garage.
     *
     * @return list<Vehicle>
     */
    public function findCandidates(string $make, string $model, VehicleType $vehicleType, ?int $carId, ?string $plate, ?string $vin): array
    {
        $qb = $this->createQueryBuilder('v')
            ->andWhere('v.owner IS NULL')
            ->andWhere('v.vehicleType = :vehicleType')
            ->setParameter('vehicleType', $vehicleType)
            ->orderBy('v.addedAt', 'DESC');

        $match = $qb->expr()->orX('LOWER(v.model) = LOWER(:model)');
        $qb->setParameter('model', $model);

        if (null !== $carId) {
            $match->add('v.kType = :carId');
            $qb->setParameter('carId', $carId);
        }
        if (null !== $plate) {
            $match->add('LOWER(v.plate) = LOWER(:plate)');
            $qb->setParameter('plate', $plate);
        }
        if (null !== $vin) {
            $match->add('LOWER(v.vin) = LOWER(:vin)');
            $qb->setParameter('vin', $vin);
        }

        $qb->andWhere($match);

        return $qb->getQuery()->getResult();
    }

    /**
     * List the shared garage (owner IS NULL — the phase 1 tenancy seam), newest first,
     * narrowed by an optional case-insensitive make and/or vehicleType. Per-user listings
     * arrive with the owner backfill (TODO.md "## Garage"), reusing this query.
     *
     * @return list<Vehicle>
     */
    public function findShared(?string $make = null, ?string $model = null, ?VehicleType $vehicleType = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->andWhere('v.owner IS NULL')
            ->orderBy('v.addedAt', 'DESC');

        if (null !== $make) {
            $qb->andWhere('LOWER(v.make) = LOWER(:make)')->setParameter('make', $make);
        }

        if (null !== $model) {
            $qb->andWhere('LOWER(v.model) = LOWER(:model)')->setParameter('model', $model);
        }

        if (null !== $vehicleType) {
            $qb->andWhere('v.vehicleType = :vehicleType')->setParameter('vehicleType', $vehicleType);
        }

        return $qb->getQuery()->getResult();
    }
}
