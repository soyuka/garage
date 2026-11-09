<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class ListDueMaintenanceInput
{
    #[ApiProperty(description: 'Restrict to a single make, e.g. "IVECO". Case-insensitive. Omit to forecast the whole garage.')]
    #[Assert\Length(max: 100)]
    public ?string $make = null;

    #[ApiProperty(description: 'Restrict to a single model, e.g. "DAILY". Case-insensitive. Pair with make to forecast one vehicle.')]
    #[Assert\Length(max: 100)]
    public ?string $model = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Restrict to a vehicle segment.'])]
    #[Assert\Length(max: 100)]
    public ?string $vehicleType = null;

    #[ApiProperty(description: 'Only return services due within this many days from now (overdue services are always included). Omit for every upcoming service.')]
    public ?int $withinDays = null;

    #[ApiProperty(description: 'Cap the number of due services returned (soonest due first). Use 1 to answer "what is the next vehicle to maintain?".')]
    #[Assert\Range(min: 1, max: 50)]
    public ?int $limit = null;
}
