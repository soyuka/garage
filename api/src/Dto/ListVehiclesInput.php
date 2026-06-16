<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class ListVehiclesInput
{
    #[ApiProperty(description: 'Filter by make, e.g. "IVECO". Case-insensitive. Omit to list every make.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Filter by model, e.g. "246-H". Case-insensitive. Pair with make to check whether a specific vehicle is already stored.')]
    public ?string $model = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Filter by vehicle segment. Omit to list every segment.'])]
    public ?string $vehicleType = null;
}
