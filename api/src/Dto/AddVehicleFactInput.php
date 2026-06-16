<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class AddVehicleFactInput
{
    #[ApiProperty(description: 'Target an existing vehicle by its id (from list_vehicles) — the way to resolve an ambiguous_match: the fact is written straight onto that row. When set, make/model are ignored.')]
    public ?int $vehicleId = null;

    #[ApiProperty(description: 'Vehicle make, e.g. "TORO". Case-insensitive. Required unless vehicleId is given.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Vehicle model, e.g. "246-H". Case-insensitive. Required unless vehicleId is given.')]
    public ?string $model = null;

    #[ApiProperty(description: 'Model year, when known.')]
    public ?int $year = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Vehicle segment. Defaults to "car".'])]
    public string $vehicleType = 'car';

    #[ApiProperty(description: 'TecDoc kType (carId) of the vehicle, when known. Stored on the vehicle and used to recognise the same vehicle stored under another model label.')]
    public ?int $carId = null;

    #[ApiProperty(description: 'Registration plate, when known. Stored on the vehicle and used to recognise the same vehicle.')]
    public ?string $plate = null;

    #[ApiProperty(description: 'VIN, when known. Stored on the vehicle and used to recognise the same vehicle.')]
    public ?string $vin = null;

    #[ApiProperty(description: 'Set true to create a NEW vehicle even though candidates were surfaced — i.e. you have judged it is genuinely a different vehicle. Defaults to false.')]
    public bool $confirmNew = false;

    #[ApiProperty(description: 'Fact key, e.g. "oil_type", "oil_capacity", "spark_plug_ref".')]
    public string $key;

    #[ApiProperty(description: 'Fact value, e.g. "SAE 5W-30", "3.5".')]
    public string $value;

    #[ApiProperty(description: 'Unit when the value is a quantity, e.g. "liter".')]
    public ?string $unit = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['llm', 'user', 'web'], 'description' => 'Where the value came from. Defaults to "llm" (general knowledge, unverified). Use "web" with a url for a sourced fact.'])]
    public string $source = 'llm';

    #[ApiProperty(description: 'Source URL when source is web-sourced. Stored on the fact as provenance.')]
    public ?string $url = null;

    #[ApiProperty(description: 'Who/what added the fact (optional attribution).')]
    public ?string $addedBy = null;
}
