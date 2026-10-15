<?php

declare(strict_types=1);

namespace App\Vehicle;

use App\ApiResource\Vehicle;
use App\Siv\SivClient;

final class VehicleLookup
{
    public function __construct(
        private readonly SivClient $sivClient,
        private readonly VehicleIndex $index,
    ) {
    }

    public function byPlate(string $plate): ?Vehicle
    {
        $data = $this->sivClient->lookup($plate);
        $vehicle = null !== $data ? Vehicle::fromSiv($data) : null;
        if (null !== $vehicle && null !== $vehicle->carId) {
            $this->index->record($vehicle->carId, $vehicle->carType, $vehicle->make, $vehicle->model, implode(' · ', array_filter([$vehicle->version, $vehicle->engineCode])), 'siv');
        }

        return $vehicle;
    }
}
