<?php

declare(strict_types=1);

namespace App\Vehicle;

use App\ApiResource\Vehicle;
use App\Siv\SivClient;

final class VehicleLookup
{
    public function __construct(private readonly SivClient $sivClient)
    {
    }

    public function byPlate(string $plate): ?Vehicle
    {
        $data = $this->sivClient->lookup($plate);

        return null !== $data ? Vehicle::fromSiv($data) : null;
    }
}
