<?php

declare(strict_types=1);

namespace App\Garage;

use App\Catalog\PartsCatalog;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Repository\VehicleRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * The read resolver for the self-strengthening store.
 *
 * Phase 1 read order: shared garage → upstream (write-through) → miss. The "user garage"
 * and promotion layers are deferred (no auth yet — see docs/TODO.md "## Garage").
 */
final class GarageResolver
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

        // The catalog fluid specs land on a KNOWN item (no target → off the checklist); the facts
        // projection reads them back. source 'partsapi' ⇒ verified, so they take precedence over any
        // agent-supplied spec for the same key.
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

        $this->entityManager->flush();

        return $vehicle;
    }
}
