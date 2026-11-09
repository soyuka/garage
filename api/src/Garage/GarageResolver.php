<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * The read resolver for the self-strengthening store.
 *
 * Phase 1 read order: shared garage → miss. The "user garage"
 * and promotion layers are deferred (no auth yet — see the root TODO.md "Architecture — Garage store").
 */
class GarageResolver
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DocumentManager $entityManager,
        private readonly VehicleWriteResolver $writeResolver,
    ) {
    }

    /**
     * Resolve a vehicle straight from its ULID — the read counterpart of addressing a write by @id.
     * Used when the caller already holds the dereferenceable handle (e.g. the dashboard's selected
     * vehicle) and need not re-match on make/model. Returns null unless the current owner holds a
     * live row under that id.
     */
    public function resolveById(Ulid $id): ?Vehicle
    {
        return $this->vehicles->findOwned($id);
    }

    /**
     * Resolve a vehicle from the shared garage. Returns null on a miss — the caller turns that into
     * the not_in_catalog contract.
     */
    public function resolve(string $make, string $model, ?int $year, VehicleType $vehicleType): ?Vehicle
    {
        return $this->vehicles->findByIdentity($make, $model, $vehicleType, $year);
    }

    /**
     * Patch the vehicle's OWN scalar columns (usage reading, owner, plate, vin, year, engineVariant)
     * on the row chosen by the shared {@see VehicleWriteResolver} (matched, addressed by id, or
     * freshly created). Surfaces the resolver's ambiguous/not_found verdicts as outcomes rather
     * than writing. Only the provided (non-null) fields are applied — omitted ones are untouched.
     * This never writes facts (those are recorded via set_vehicle_facts).
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
        ?string $engineVariant,
        ?bool $archived = null,
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
        if (null !== $engineVariant) {
            $vehicle->setEngineVariant($engineVariant);
        }
        if (true === $archived) {
            $vehicle->archive(new \DateTimeImmutable());
        } elseif (false === $archived) {
            $vehicle->unarchive();
        }

        $this->entityManager->flush();

        return UpdateVehicleOutcome::written($vehicle);
    }
}
