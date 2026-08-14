<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class SelectVehicleInput
{
    #[ApiProperty(description: 'The vehicle @id to focus, exactly as list_vehicles returns it (e.g. "/garage/vehicles/01J…").')]
    public string $vehicle = '';
}
