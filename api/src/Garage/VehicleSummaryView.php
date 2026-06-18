<?php

declare(strict_types=1);

namespace App\Garage;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\Vehicle;

/**
 * Flattens a garage Vehicle into the summary row the list_vehicles tool surfaces — identity,
 * provenance and a factCount so the LLM knows which vehicles are worth a get_vehicle_facts
 * follow-up, without inlining every fact into the listing. The `@id` is the dereferenceable
 * GarageVehicle IRI: the agent reuses it as the `vehicle` input and can GET it directly.
 */
final class VehicleSummaryView
{
    public function __construct(private readonly IriConverterInterface $iriConverter)
    {
    }

    /**
     * @return array{make: string, model: string, year: int|null, vehicleType: string, source: string, kType: int|null, plate: string|null, vin: string|null, owner: string|null, usageValue: int|null, usageUnit: string|null, factCount: int, openTaskCount: int, addedAt: string}
     */
    public function toArray(Vehicle $vehicle): array
    {
        return [
            '@id' => $this->iriConverter->getIriFromResource(GarageVehicle::fromEntity($vehicle)),
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'year' => $vehicle->getYear(),
            'vehicleType' => $vehicle->getVehicleType()->value,
            'source' => $vehicle->getSource(),
            'kType' => $vehicle->getKType(),
            'plate' => $vehicle->getPlate(),
            'vin' => $vehicle->getVin(),
            'owner' => $vehicle->getOwner(),
            'usageValue' => $vehicle->getUsageValue(),
            'usageUnit' => $vehicle->getUsageUnit()?->value,
            'factCount' => $vehicle->getFacts()->count(),
            'openTaskCount' => $vehicle->openTaskCount(),
            'addedAt' => $vehicle->getAddedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
