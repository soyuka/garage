<?php

declare(strict_types=1);

namespace App\Garage;

use App\Catalog\PartsCatalog;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Repository\VehicleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * The read resolver for the self-strengthening store.
 *
 * Phase 1 read order: shared garage → upstream (write-through) → miss. The "user garage"
 * and promotion layers are deferred (no auth yet — see docs/TODO.md "## Garage").
 */
final class GarageResolver
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly EntityManagerInterface $entityManager,
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
     * Write a fact onto the vehicle chosen by the shared {@see VehicleWriteResolver} (matched,
     * addressed by id, or freshly created). Surfaces the resolver's ambiguous/not_found verdicts
     * as outcomes rather than writing. Returns a refusal (store untouched) when an existing
     * verified authoritative fact would be overwritten by an llm/user value — the precedence
     * rule that stops the DB rotting.
     */
    public function addFact(
        ?Ulid $vehicleId,
        ?string $make,
        ?string $model,
        ?int $year,
        VehicleType $vehicleType,
        ?int $carId,
        ?string $plate,
        ?string $vin,
        bool $confirmNew,
        string $key,
        string $value,
        ?string $unit,
        string $source,
        ?string $url,
        ?string $addedBy,
    ): AddFactOutcome {
        $resolution = $this->writeResolver->resolveForWrite(
            $vehicleId, $make, $model, $year, $vehicleType, $carId, $plate, $vin, $confirmNew, $source,
        );

        return match ($resolution->status) {
            'ambiguous' => AddFactOutcome::ambiguous($resolution->candidates),
            'not_found' => AddFactOutcome::notFound($resolution->vehicleId),
            default => $this->writeFact($resolution->vehicle, $key, $value, $unit, $source, $url, $addedBy),
        };
    }

    /**
     * Patch the vehicle's OWN scalar columns (usage reading, owner, plate, vin, year, modification)
     * on the row chosen by the shared {@see VehicleWriteResolver} (matched, addressed by id, or
     * freshly created). Surfaces the resolver's ambiguous/not_found verdicts as outcomes rather
     * than writing. Only the provided (non-null) fields are applied — omitted ones are untouched.
     * This never writes facts (that stays add_vehicle_fact), so there is no precedence rule.
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

    private function writeFact(
        Vehicle $vehicle,
        string $key,
        string $value,
        ?string $unit,
        string $source,
        ?string $url,
        ?string $addedBy,
    ): AddFactOutcome {
        $existing = $vehicle->factByKey($key);
        if (null !== $existing && $existing->isVerified() && !$this->isAuthoritative($source)) {
            return AddFactOutcome::refused($existing);
        }

        $verified = $this->isAuthoritative($source);

        // One fact per (vehicle, key): update in place rather than inserting a duplicate that
        // factByKey() would shadow with a stale value (UNIQUE(vehicle_id, fact_key)).
        if (null !== $existing) {
            $existing->update($value, $source, $verified, $unit, $addedBy, $url);
            $this->entityManager->flush();

            return AddFactOutcome::written($existing);
        }

        $fact = new VehicleFact(
            vehicle: $vehicle,
            key: $key,
            value: $value,
            source: $source,
            verified: $verified,
            unit: $unit,
            addedBy: $addedBy,
            url: $url,
        );
        $this->entityManager->persist($fact);
        $this->entityManager->flush();

        return AddFactOutcome::written($fact);
    }

    private function writeThroughFillVolumes(string $make, string $model, ?int $year, VehicleType $vehicleType, int $carId): ?Vehicle
    {
        $facts = $this->fillVolumeFactMapper->toFacts($this->catalog->fillVolumes($carId));
        if ([] === $facts) {
            return null;
        }

        $vehicle = new Vehicle($make, $model, $vehicleType, 'partsapi', year: $year, kType: $carId);
        $this->entityManager->persist($vehicle);

        foreach ($facts as $fact) {
            $this->entityManager->persist(new VehicleFact(
                vehicle: $vehicle,
                key: $fact['key'],
                value: $fact['value'],
                source: 'partsapi',
                verified: true,
                unit: $fact['unit'],
            ));
        }

        $this->entityManager->flush();

        return $vehicle;
    }

    private function isAuthoritative(string $source): bool
    {
        return \in_array($source, ['partsapi', 'siv'], true);
    }
}
