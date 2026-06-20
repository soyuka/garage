<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class UpdateVehicleInput
{
    #[ApiProperty(description: 'Target an existing vehicle by its @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…") — the way to resolve an ambiguous_match: the update is applied straight to that row. When set, make/model are ignored.')]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'Vehicle make, e.g. "TORO". Case-insensitive. Required unless vehicle is given.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Vehicle model, e.g. "246-H". Case-insensitive. Required unless vehicle is given.')]
    public ?string $model = null;

    #[ApiProperty(description: 'Model year. Doubles as an identity hint AND, when set, is applied to the resolved vehicle.')]
    public ?int $year = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Vehicle segment. Defaults to "car".'])]
    public string $vehicleType = 'car';

    #[ApiProperty(description: 'TecDoc kType (carId) of the vehicle, when known. Used to recognise the same vehicle stored under another model label.')]
    public ?int $carId = null;

    #[ApiProperty(description: 'Registration plate. Doubles as an identity hint AND, when set, is applied to the resolved vehicle.')]
    public ?string $plate = null;

    #[ApiProperty(description: 'VIN. Doubles as an identity hint AND, when set, is applied to the resolved vehicle.')]
    public ?string $vin = null;

    #[ApiProperty(description: 'Set true to create a NEW vehicle even though candidates were surfaced — i.e. you have judged it is genuinely a different vehicle. Defaults to false.')]
    public bool $confirmNew = false;

    #[ApiProperty(description: 'Last recorded usage reading (odometer for road vehicles, engine hours for groundscare). Must be sent together with usageUnit.')]
    public ?int $usageValue = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['km', 'mi', 'h'], 'description' => 'Unit of usageValue: "km", "mi" or engine "h". Must be sent together with usageValue.'])]
    public ?string $usageUnit = null;

    #[ApiProperty(description: 'Trim/modification label, e.g. "35S14".')]
    public ?string $modification = null;
}
