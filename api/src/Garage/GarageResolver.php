<?php

declare(strict_types=1);

namespace App\Garage;

use App\Catalog\PartsCatalog;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Repository\VehicleRepository;
use Doctrine\ORM\EntityManagerInterface;

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
     * Create the shared vehicle row if absent and write the fact. Returns a refusal (leaving
     * the store untouched) when an existing verified authoritative fact would be overwritten
     * by an llm/user value — the precedence rule that stops the DB rotting.
     */
    public function addFact(
        string $make,
        string $model,
        ?int $year,
        VehicleType $vehicleType,
        string $key,
        string $value,
        ?string $unit,
        string $source,
        ?string $url,
        ?string $addedBy,
    ): AddFactOutcome {
        $vehicle = $this->vehicles->findByIdentity($make, $model, $vehicleType, $year);
        if (null === $vehicle) {
            $vehicle = new Vehicle($make, $model, $vehicleType, $source, year: $year);
            $this->entityManager->persist($vehicle);
        }

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
