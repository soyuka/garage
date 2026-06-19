<?php

declare(strict_types=1);

namespace App\Garage;

use App\Catalog\PartsCatalog;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Exception\BudgetExhaustedException;
use App\Exception\MissingApiKeyException;
use App\Exception\UpstreamException;
use App\Repository\VehicleRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Ulid;

/**
 * The read resolver for the self-strengthening store.
 *
 * Phase 1 read order: shared garage → upstream (write-through) → miss. The "user garage"
 * and promotion layers are deferred (no auth yet — see the root TODO.md "Architecture — Garage store").
 */
class GarageResolver
{
    // The synthetic KNOWN item the catalog write-through hangs its fill-volume specs on: an open,
    // untargeted maintenance item (off the checklist) whose attributes feed the facts projection.
    // The vehicle has no fact store of its own, so the catalog spec sheet IS a maintenance item.
    public const string CATALOG_SPECS_TYPE = 'catalog_specs';

    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DocumentManager $entityManager,
        private readonly PartsCatalog $catalog,
        private readonly FillVolumeFactMapper $fillVolumeFactMapper,
        private readonly VehicleWriteResolver $writeResolver,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Resolve a vehicle from the shared garage, falling back to a write-through of upstream
     * fill volumes when a carId is supplied. Returns null on a total miss (no garage row and
     * no usable upstream data) — the caller turns that into the not_in_catalog contract.
     */
    /**
     * Resolve a vehicle straight from its ULID — the read counterpart of addressing a write by @id.
     * Used when the caller already holds the dereferenceable handle (e.g. the dashboard's selected
     * vehicle) and need not re-match on make/model. Returns null when no such row exists.
     */
    public function resolveById(Ulid $id): ?Vehicle
    {
        return $this->vehicles->find($id);
    }

    public function resolve(string $make, string $model, ?int $year, VehicleType $vehicleType, ?int $carId): ?Vehicle
    {
        $vehicle = $this->vehicles->findByIdentity($make, $model, $vehicleType, $year);
        if (null !== $vehicle) {
            return $vehicle;
        }

        if (null === $carId) {
            return null;
        }

        return $this->writeThroughFillVolumes($make, $model, $year, $vehicleType, $carId);
    }

    /**
     * Self-heal a vehicle whose kType was known all along but whose catalog spec sheet was never
     * fetched — the state a row added by plate lookup / update_vehicle is in (those write the
     * identity and kType but never the fill volumes). Fetches the catalog fluid specs once and
     * hangs them on a {@see self::CATALOG_SPECS_TYPE} item, exactly like the create-time
     * write-through. Idempotent: a row that already carries the spec sheet (or has no kType, or
     * whose kType yields nothing upstream) is left untouched. Returns true only when it wrote.
     *
     * Side-effecting on a read is the self-strengthening-store contract (the create-time
     * write-through already writes on a get_vehicle_facts miss). Upstream responses are cached at
     * the client, so a kType that yields nothing is not re-fetched on the hot path beyond the cache.
     */
    public function backfillCatalogSpecs(Vehicle $vehicle): bool
    {
        $carId = $vehicle->getKType();
        if (null === $carId) {
            return false;
        }
        foreach ($vehicle->getPlannedTasks() as $task) {
            if (self::CATALOG_SPECS_TYPE === $task->getType()) {
                return false;
            }
        }

        // Best-effort enrichment on a plain read: an upstream hiccup (partsapi quota 401, timeout,
        // missing key) must never 500 the vehicle GET. Swallow it — the card still renders, facts
        // just stay un-backfilled until the next read finds the catalog reachable.
        try {
            $volumes = $this->catalog->fillVolumes($carId);
        } catch (UpstreamException|BudgetExhaustedException|MissingApiKeyException $e) {
            $this->logger->warning('catalog spec backfill skipped: {reason}', [
                'reason' => $e->getMessage(),
                'kType' => $carId,
                'exception' => $e,
            ]);

            return false;
        }

        $facts = $this->fillVolumeFactMapper->toFacts($volumes);
        if ([] === $facts) {
            return false;
        }

        $this->persistCatalogSpecs($vehicle, $facts);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Patch the vehicle's OWN scalar columns (usage reading, owner, plate, vin, year, modification)
     * on the row chosen by the shared {@see VehicleWriteResolver} (matched, addressed by id, or
     * freshly created). Surfaces the resolver's ambiguous/not_found verdicts as outcomes rather
     * than writing. Only the provided (non-null) fields are applied — omitted ones are untouched.
     * This never writes facts (those are recorded via plan_maintenance attributes).
     */
    public function updateVehicle(
        ?Ulid $vehicleId,
        ?string $make,
        ?string $model,
        ?int $year,
        VehicleType $vehicleType,
        ?int $carId,
        ?string $plate,
        ?string $vin,
        bool $confirmNew,
        ?int $usageValue,
        ?UsageUnit $usageUnit,
        ?string $owner,
        ?string $modification,
    ): UpdateVehicleOutcome {
        $resolution = $this->writeResolver->resolveForWrite(
            $vehicleId, $make, $model, $year, $vehicleType, $carId, $plate, $vin, $confirmNew, 'user',
        );

        if ('ambiguous' === $resolution->status) {
            return UpdateVehicleOutcome::ambiguous($resolution->candidates);
        }

        if ('not_found' === $resolution->status) {
            return UpdateVehicleOutcome::notFound($resolution->vehicleId);
        }

        $vehicle = $resolution->vehicle;

        if (null !== $usageValue && null !== $usageUnit) {
            $vehicle->setUsage($usageValue, $usageUnit);
        }
        if (null !== $year) {
            $vehicle->setYear($year);
        }
        if (null !== $plate) {
            $vehicle->setPlate($plate);
        }
        if (null !== $vin) {
            $vehicle->setVin($vin);
        }
        if (null !== $owner) {
            $vehicle->setOwner($owner);
        }
        if (null !== $modification) {
            $vehicle->setModification($modification);
        }

        $this->entityManager->flush();

        return UpdateVehicleOutcome::written($vehicle);
    }

    private function writeThroughFillVolumes(string $make, string $model, ?int $year, VehicleType $vehicleType, int $carId): ?Vehicle
    {
        $facts = $this->fillVolumeFactMapper->toFacts($this->catalog->fillVolumes($carId));
        if ([] === $facts) {
            return null;
        }

        $vehicle = new Vehicle($make, $model, $vehicleType, 'partsapi', year: $year, kType: $carId);
        $this->entityManager->persist($vehicle);
        $this->persistCatalogSpecs($vehicle, $facts);
        $this->entityManager->flush();

        return $vehicle;
    }

    /**
     * Hang the catalog fluid specs on a KNOWN item (no target → off the checklist); the facts
     * projection reads them back. source 'partsapi' ⇒ verified, so they take precedence over any
     * agent-supplied spec for the same key. Persists the item; the caller owns the flush.
     *
     * @param list<array{key: string, value: string, unit: string|null}> $facts
     */
    private function persistCatalogSpecs(Vehicle $vehicle, array $facts): void
    {
        $specs = new PlannedMaintenance($vehicle, self::CATALOG_SPECS_TYPE, 'partsapi');
        foreach ($facts as $fact) {
            $specs->setAttribute(VehicleFact::fromSource(
                key: $fact['key'],
                value: $fact['value'],
                source: 'partsapi',
                unit: $fact['unit'],
            ));
        }
        $this->entityManager->persist($specs);
    }
}
