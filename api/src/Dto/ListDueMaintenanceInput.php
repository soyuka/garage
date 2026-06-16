<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class ListDueMaintenanceInput
{
    #[ApiProperty(description: 'Restrict to a single make, e.g. "IVECO". Case-insensitive. Omit to forecast the whole garage.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Restrict to a single model, e.g. "DAILY". Case-insensitive. Pair with make to forecast one vehicle.')]
    public ?string $model = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Restrict to a vehicle segment.'])]
    public ?string $vehicleType = null;

    #[ApiProperty(description: 'Only return services due within this many days from now (overdue services are always included). Omit for every upcoming service.')]
    public ?int $withinDays = null;

    #[ApiProperty(description: 'Cap the number of due services returned (soonest due first). Use 1 to answer "what is the next vehicle to maintain?".')]
    public ?int $limit = null;
}
