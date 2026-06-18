<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class GetMaintenanceHistoryInput
{
    #[ApiProperty(description: 'Target an existing vehicle by its @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…"), e.g. the one the dashboard has selected. When set, make/model are ignored.')]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'Vehicle make, e.g. "TORO", "IVECO". Case-insensitive. Required unless vehicle is given.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Vehicle model, e.g. "246-H", "DAILY". Case-insensitive. Required unless vehicle is given.')]
    public ?string $model = null;

    #[ApiProperty(description: 'Model year, when known.')]
    public ?int $year = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Vehicle segment. Defaults to "car".'])]
    public string $vehicleType = 'car';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['oil_change', 'oil_filter', 'air_filter', 'fuel_filter', 'cabin_filter', 'spark_plug', 'tires', 'brakes', 'coolant', 'battery', 'inspection', 'repair', 'other'], 'description' => 'Optional: only return records of this type. Omit for the full history.'])]
    public ?string $type = null;

    #[ApiProperty(description: 'Optional cap on the number of records returned (newest first).')]
    public ?int $limit = null;
}
