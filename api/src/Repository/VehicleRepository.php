<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use MongoDB\BSON\Regex;

/**
 * @extends ServiceDocumentRepository<Vehicle>
 */
final class VehicleRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    /**
     * Case-insensitive identity match on make + model + vehicleType, narrowed by year when
     * given. vehicleType is part of identity (TODO.md data model): a `groundscare` TORO and a
     * `cv` document sharing make+model are distinct vehicles and must not bind facts onto each
     * other. Phase 1 looks only at the shared garage (owner IS NULL).
     */
    public function findByIdentity(string $make, string $model, VehicleType $vehicleType, ?int $year = null): ?Vehicle
    {
        $qb = $this->createQueryBuilder()
            ->field('make')->equals(self::ciExact($make))
            ->field('model')->equals(self::ciExact($model))
            ->field('vehicleType')->equals($vehicleType->value)
            ->field('owner')->equals(null)
            ->limit(1);

        if (null !== $year) {
            $qb->field('year')->equals($year);
        }

        return $qb->getQuery()->getSingleResult();
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
        $qb = $this->createQueryBuilder()
            ->field('owner')->equals(null)
            ->field('vehicleType')->equals($vehicleType->value)
            ->sort('addedAt', 'desc');

        $match = [$qb->expr()->field('model')->equals(self::ciExact($model))];

        if (null !== $carId) {
            $match[] = $qb->expr()->field('kType')->equals($carId);
        }
        if (null !== $plate) {
            $match[] = $qb->expr()->field('plate')->equals(self::ciExact($plate));
        }
        if (null !== $vin) {
            $match[] = $qb->expr()->field('vin')->equals(self::ciExact($vin));
        }

        $qb->addAnd($qb->expr()->addOr(...$match));

        return array_values($qb->getQuery()->execute()->toArray());
    }

    /**
     * List the shared garage (owner IS NULL — the phase 1 tenancy seam), newest first,
     * narrowed by an optional case-insensitive make and/or model and/or vehicleType. Per-user
     * listings arrive with the owner backfill (TODO.md "## Garage"), reusing this query.
     *
     * @return list<Vehicle>
     */
    public function findShared(?string $make = null, ?string $model = null, ?VehicleType $vehicleType = null): array
    {
        $qb = $this->createQueryBuilder()
            ->field('owner')->equals(null)
            ->sort('addedAt', 'desc');

        if (null !== $make) {
            $qb->field('make')->equals(self::ciExact($make));
        }

        if (null !== $model) {
            $qb->field('model')->equals(self::ciExact($model));
        }

        if (null !== $vehicleType) {
            $qb->field('vehicleType')->equals($vehicleType->value);
        }

        return array_values($qb->getQuery()->execute()->toArray());
    }

    /**
     * A case-insensitive exact-match anchored regex — the ODM equivalent of `LOWER(x) = LOWER(:v)`.
     */
    private static function ciExact(string $value): Regex
    {
        return new Regex('^'.preg_quote($value, '/').'$', 'i');
    }
}
