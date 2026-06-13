<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class GetVehicleFactsInput
{
    #[ApiProperty(description: 'Vehicle make, e.g. "TORO", "IVECO". Case-insensitive.')]
    public string $make;

    #[ApiProperty(description: 'Vehicle model, e.g. "246-H", "DAILY". Case-insensitive.')]
    public string $model;

    #[ApiProperty(description: 'Model year, when known.')]
    public ?int $year = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Vehicle segment. Defaults to "car".'])]
    public string $vehicleType = 'car';

    #[ApiProperty(description: 'TecDoc kType (carId) from lookup_plate or list_cars. When given and the garage misses, fluid facts are fetched from the catalog and persisted.')]
    public ?int $carId = null;

    /**
     * @var list<string>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Fact keys to retrieve, e.g. ["oil_type", "oil_capacity"]. Empty = all known facts.'])]
    public array $keys = [];
}
