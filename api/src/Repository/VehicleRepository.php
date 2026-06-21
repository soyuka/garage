<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Security\OwnerContext;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use Doctrine\ODM\MongoDB\Query\Builder;

/**
 * Every query is scoped to the current tenant via {@see OwnerContext}: `owner` leads each lookup
 * (and each index — see {@see Vehicle}), so one user's garage is never visible to another. The
 * owner is the authenticated Casdoor `sub`, resolved from the security token on an HTTP request or
 * bound explicitly by the agent worker for the turn it is running.
 *
 * @extends ServiceDocumentRepository<Vehicle>
 */
final class VehicleRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry, private readonly OwnerContext $owner)
    {
        parent::__construct($registry, Vehicle::class);
    }

    /**
     * Case-insensitive identity match on make + model + vehicleType, narrowed by year when
     * given. vehicleType is part of identity (TODO.md data model): a `groundscare` TORO and a
     * `cv` document sharing make+model are distinct vehicles and must not bind facts onto each
     * other. Scoped to the current owner.
     */
    public function findByIdentity(string $make, string $model, VehicleType $vehicleType, ?int $year = null): ?Vehicle
    {
        $qb = $this->createQueryBuilder()
            ->field('make')->equals($make)
            ->field('model')->equals($model)
            ->field('vehicleType')->equals($vehicleType->value)
            ->field('owner')->equals($this->owner->current())
            ->field('deletedAt')->equals(null)
            ->limit(1);

        if (null !== $year) {
            $qb->field('year')->equals($year);
        }

        return $qb->getQuery(['collation' => Vehicle::IDENTITY_COLLATION])->getSingleResult();
    }

    /**
     * Surface stored vehicles that may be the same one as an incoming write but did NOT match
     * on exact identity — the input for the agent's reconcile-or-create decision. Within the
     * same segment (vehicleType), a candidate is anything sharing the model label (catches a
     * rebadged machine: TORO vs Wheel Horse 246-H) or a strong stable id (kType/plate/vin,
     * catches the same TecDoc vehicle stored under two model strings). Deliberately broad: the
     * agent judges, these ids only rank what it confronts. Scoped to the current owner.
     *
     * @return list<Vehicle>
     */
    public function findCandidates(string $make, string $model, VehicleType $vehicleType, ?int $carId, ?string $plate, ?string $vin): array
    {
        $qb = $this->createQueryBuilder()
            ->field('owner')->equals($this->owner->current())
            ->field('vehicleType')->equals($vehicleType->value)
            ->field('deletedAt')->equals(null)
            ->sort('addedAt', 'desc');

        $match = [$qb->expr()->field('model')->equals($model)];

        if (null !== $carId) {
            $match[] = $qb->expr()->field('kType')->equals($carId);
        }
        if (null !== $plate) {
            $match[] = $qb->expr()->field('plate')->equals($plate);
        }
        if (null !== $vin) {
            $match[] = $qb->expr()->field('vin')->equals($vin);
        }

        $qb->addAnd($qb->expr()->addOr(...$match));

        return array_values($qb->getQuery(['collation' => Vehicle::IDENTITY_COLLATION])->execute()->toArray());
    }

    /**
     * The current owner's vehicles carrying a given TecDoc kType — the handle get_service_parts is
     * keyed on (carId). Used to opportunistically backfill those vehicles' empty checklist tasks with
     * the priced parts. Newest first; a kType shared by two stored rows backfills both (additive, safe).
     *
     * @return list<Vehicle>
     */
    public function findByKType(int $kType): array
    {
        return array_values($this->createQueryBuilder()
            ->field('owner')->equals($this->owner->current())
            ->field('kType')->equals($kType)
            ->field('deletedAt')->equals(null)
            ->sort('addedAt', 'desc')
            ->getQuery()->execute()->toArray());
    }

    /**
     * List the current owner's garage, MOST-RECENTLY-TOUCHED first (updatedAt desc, addedAt as the
     * tiebreak — legacy docs with no updatedAt fall back to creation order), narrowed by an optional
     * case-insensitive make and/or model and/or vehicleType. This is the set the dashboard sidebar
     * and the MCP list_vehicles tool show. Pass $limit to page the result (with $offset); $limit null
     * returns the whole parc (the dashboard, which reorders client-side).
     *
     * @return list<Vehicle>
     */
    public function findShared(?string $make = null, ?string $model = null, ?VehicleType $vehicleType = null, ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->sharedQuery($make, $model, $vehicleType)
            ->sort(['updatedAt' => 'desc', 'addedAt' => 'desc']);

        if (null !== $limit) {
            $qb->limit($limit)->skip(max(0, $offset));
        }

        return array_values($qb->getQuery(['collation' => Vehicle::IDENTITY_COLLATION])->execute()->toArray());
    }

    /**
     * Total size of the current owner's garage for the same filters as {@see findShared()} — lets the
     * paginated list_vehicles report how many vehicles exist beyond the returned page.
     */
    public function countShared(?string $make = null, ?string $model = null, ?VehicleType $vehicleType = null): int
    {
        return $this->sharedQuery($make, $model, $vehicleType)
            ->count()
            ->getQuery(['collation' => Vehicle::IDENTITY_COLLATION])
            ->execute();
    }

    private function sharedQuery(?string $make, ?string $model, ?VehicleType $vehicleType): Builder
    {
        $qb = $this->createQueryBuilder()
            ->field('owner')->equals($this->owner->current())
            ->field('deletedAt')->equals(null);

        if (null !== $make) {
            $qb->field('make')->equals($make);
        }

        if (null !== $model) {
            $qb->field('model')->equals($model);
        }

        if (null !== $vehicleType) {
            $qb->field('vehicleType')->equals($vehicleType->value);
        }

        return $qb;
    }
}
