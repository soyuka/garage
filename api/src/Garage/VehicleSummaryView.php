<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\Vehicle;

/**
 * Flattens a garage Vehicle into the summary row the list_vehicles tool surfaces — identity,
 * provenance and a factCount so the LLM knows which vehicles are worth a get_vehicle_facts
 * follow-up, without inlining every fact into the listing.
 */
final class VehicleSummaryView
{
    /**
     * @return array{make: string, model: string, year: int|null, vehicleType: string, source: string, kType: int|null, plate: string|null, vin: string|null, factCount: int, addedAt: string}
     */
    public static function toArray(Vehicle $vehicle): array
    {
        return [
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'year' => $vehicle->getYear(),
            'vehicleType' => $vehicle->getVehicleType()->value,
            'source' => $vehicle->getSource(),
            'kType' => $vehicle->getKType(),
            'plate' => $vehicle->getPlate(),
            'vin' => $vehicle->getVin(),
            'factCount' => $vehicle->getFacts()->count(),
            'addedAt' => $vehicle->getAddedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
