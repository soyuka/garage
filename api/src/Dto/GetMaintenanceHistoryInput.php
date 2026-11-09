<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class GetMaintenanceHistoryInput
{
    #[ApiProperty(description: 'Target an existing vehicle by its @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…"), e.g. the one the dashboard has selected. When set, make/model are ignored.')]
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

    #[ApiProperty(schema: ['type' => 'string', 'description' => 'Optional: only return records of this snake_case type (e.g. oil_change, timing_belt). Omit for the full history.'])]
    #[Assert\Length(max: 100)]
    public ?string $type = null;

    #[ApiProperty(description: 'Optional cap on the number of records returned (newest first).')]
    #[Assert\Range(min: 1, max: 50)]
    public ?int $limit = null;
}
