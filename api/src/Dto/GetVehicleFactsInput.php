<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class GetVehicleFactsInput
{
    #[ApiProperty(description: 'Target an existing vehicle by its @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…"), e.g. the one the dashboard has selected. When set, make/model are ignored and the catalog write-through is skipped (the row already exists).')]
    #[Assert\Length(max: 200)]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'Vehicle make, e.g. "TORO", "IVECO". Case-insensitive. Required unless vehicle is given.')]
    #[Assert\Length(max: 100)]
    public ?string $make = null;

    #[ApiProperty(description: 'Vehicle model, e.g. "246-H", "DAILY". Case-insensitive. Required unless vehicle is given.')]
    #[Assert\Length(max: 100)]
    public ?string $model = null;

    #[ApiProperty(description: 'Model year, when known.')]
    public ?int $year = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Vehicle segment. Defaults to "car".'])]
    #[Assert\Length(max: 100)]
    public string $vehicleType = 'car';

    /**
     * @var list<string>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Fact keys to retrieve, e.g. ["oil_type", "oil_capacity"]. Empty = all known facts.'])]
    #[Assert\Count(max: 50)]
    public array $keys = [];
}
