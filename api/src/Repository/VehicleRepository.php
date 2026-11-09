<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Security\OwnerContext;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use Doctrine\ODM\MongoDB\Query\Builder;
use MongoDB\BSON\Regex;
use Symfony\Component\Uid\Ulid;

/**
 * Every query is scoped to the current tenant via {@see OwnerContext}: `owner` leads each lookup
 * (and each index — see {@see Vehicle}), so one user's garage is never visible to another. The
 * owner is the authenticated Casdoor `email` claim, resolved from the security token on an HTTP request or
 * bound explicitly by the agent worker for the turn it is running.
 *
 * @extends ServiceDocumentRepository<Vehicle>
 */
class VehicleRepository extends ServiceDocumentRepository
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
        $qb = $this->baseQuery()
            ->field('make')->equals($make)
            ->field('model')->equals($model)
            ->field('vehicleType')->equals($vehicleType->value)
            ->limit(1);

        if (null !== $year) {
            $qb->field('year')->equals($year);
        }

        return QueryResults::oneOf(Vehicle::class, $qb->getQuery(['collation' => Vehicle::IDENTITY_COLLATION])->getSingleResult());
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
        $qb = $this->baseQuery()
            ->field('vehicleType')->equals($vehicleType->value)
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

        return QueryResults::listOf(Vehicle::class, $qb->getQuery(['collation' => Vehicle::IDENTITY_COLLATION])->getIterator());
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
        return QueryResults::listOf(Vehicle::class, $this->baseQuery()
            ->field('kType')->equals($kType)
            ->sort('addedAt', 'desc')
            ->getQuery()->getIterator());
    }

    /**
     * List the current owner's garage, MOST-RECENTLY-TOUCHED first (updatedAt desc, addedAt as the
     * tiebreak — legacy docs with no updatedAt fall back to creation order), narrowed by an optional
     * case-insensitive make and/or model and/or vehicleType. This is the set the dashboard sidebar
     * and the MCP list_vehicles tool show. Archived vehicles are left out unless $includeArchived — the
     * active parc is the default view and what every parc-wide sweep walks. Pass $limit to page the result (with $offset); $limit null
     * returns the whole parc (the dashboard, which reorders client-side).
     *
     * @return list<Vehicle>
     */
    public function findShared(?string $make = null, ?string $model = null, ?VehicleType $vehicleType = null, ?int $limit = null, int $offset = 0, bool $includeArchived = false): array
    {
        $qb = $this->sharedQuery($make, $model, $vehicleType, $includeArchived)
            ->sort(['updatedAt' => 'desc', 'addedAt' => 'desc']);

        if (null !== $limit) {
            $qb->limit($limit)->skip(max(0, $offset));
        }

        return QueryResults::listOf(Vehicle::class, $qb->getQuery(['collation' => Vehicle::IDENTITY_COLLATION])->getIterator());
    }

    /**
     * Total size of the current owner's garage for the same filters as {@see findShared()} — lets the
     * paginated list_vehicles report how many vehicles exist beyond the returned page.
     */
    public function countShared(?string $make = null, ?string $model = null, ?VehicleType $vehicleType = null, bool $includeArchived = false): int
    {
        return QueryResults::count($this->sharedQuery($make, $model, $vehicleType, $includeArchived)
            ->count()
            ->getQuery(['collation' => Vehicle::IDENTITY_COLLATION])
            ->execute());
    }

    /**
     * Free-text search over the current owner's garage — the loose lookup `open_vehicle` needs, where
     * the pilot holds a spoken phrase ("la Mazda", "AB-123-CD") and not a structured make+model pair.
     * Matches a case-insensitive SUBSTRING of make, model, plate or VIN, MOST-RECENTLY-TOUCHED first,
     * so a bare "mazda" resolves to the car the user last worked on rather than an arbitrary row.
     *
     * Substring, not prefix: "6" must still find a MAZDA6. The garages this serves are personal (tens
     * of vehicles) and the owner filter bounds the scan, so the unindexed regex is affordable here.
     *
     * @return list<Vehicle>
     */
    public function searchShared(string $query, ?VehicleType $vehicleType = null, int $limit = 10): array
    {
        $needle = new Regex(preg_quote(trim($query), '/'), 'i');

        $qb = $this->baseQuery()->addOr(
            $this->createQueryBuilder()->expr()->field('make')->equals($needle),
            $this->createQueryBuilder()->expr()->field('model')->equals($needle),
            $this->createQueryBuilder()->expr()->field('plate')->equals($needle),
            $this->createQueryBuilder()->expr()->field('vin')->equals($needle),
        );

        if (null !== $vehicleType) {
            $qb->field('vehicleType')->equals($vehicleType->value);
        }

        return QueryResults::listOf(Vehicle::class, $qb
            ->sort(['updatedAt' => 'desc', 'addedAt' => 'desc'])
            ->limit(max(1, $limit))
            ->getQuery()->getIterator());
    }

    private function sharedQuery(?string $make, ?string $model, ?VehicleType $vehicleType, bool $includeArchived): Builder
    {
        $qb = $this->baseQuery();

        if (!$includeArchived) {
            $qb->field('archivedAt')->equals(null);
        }

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

    /**
     * The current owner's live vehicle under this id, or null — a foreign or soft-deleted row reads
     * exactly like a missing one. The only by-id lookup a request or a turn may use: a raw find()
     * would hand back another tenant's car to whoever guesses its ULID.
     */
    public function findOwned(Ulid|string $id): ?Vehicle
    {
        return QueryResults::oneOf(Vehicle::class, $this->baseQuery()->field('id')->equals((string) $id)->getQuery()->getSingleResult());
    }

    /**
     * The current owner's vehicle registered under this plate, compared without case, spaces or
     * dashes ("xb 301 zb" is "XB-301-ZB"): plates are stored as typed, so the comparison is done here
     * over the owner's plated rows rather than by an exact query.
     */
    public function findByPlate(string $plate): ?Vehicle
    {
        $wanted = self::normalizePlate($plate);
        if ('' === $wanted) {
            return null;
        }

        $plated = $this->baseQuery()->field('plate')->notEqual(null)->sort('addedAt', 'asc')->getQuery()->getIterator();
        foreach (QueryResults::listOf(Vehicle::class, $plated) as $vehicle) {
            if (self::normalizePlate((string) $vehicle->getPlate()) === $wanted) {
                return $vehicle;
            }
        }

        return null;
    }

    public static function normalizePlate(string $plate): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/u', '', $plate));
    }

    /**
     * The tenancy floor every lookup shares: scoped to the current owner and excluding soft-deleted
     * rows. Callers layer their own fields/sort/limit on top.
     */
    private function baseQuery(): Builder
    {
        return $this->createQueryBuilder()
            ->field('owner')->equals($this->owner->current())
            ->field('deletedAt')->equals(null);
    }
}
