<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class DismissMaintenanceInput
{
    #[ApiProperty(description: 'Target an existing vehicle by its @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…") — the way to resolve an ambiguous_match. When set, make/model are ignored.')]
    #[Assert\Length(max: 200)]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'Vehicle make, e.g. "MAZDA". Case-insensitive. Required unless vehicle is given.')]
    #[Assert\Length(max: 100)]
    public ?string $make = null;

    #[ApiProperty(description: 'Vehicle model, e.g. "MAZDA6". Case-insensitive. Required unless vehicle is given.')]
    #[Assert\Length(max: 100)]
    public ?string $model = null;

    #[ApiProperty(description: 'Model year, when known.')]
    public ?int $year = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Vehicle segment. Defaults to "car".'])]
    #[Assert\Length(max: 100)]
    public string $vehicleType = 'car';

    #[ApiProperty(description: 'TecDoc kType (carId) of the vehicle, when known. Used to recognise the same vehicle stored under another model label.')]
    public ?int $carId = null;

    #[ApiProperty(description: 'Registration plate, when known. Used to recognise the same vehicle.')]
    #[Assert\Length(max: 32)]
    public ?string $plate = null;

    #[ApiProperty(description: 'VIN, when known. Used to recognise the same vehicle.')]
    #[Assert\Length(max: 32)]
    public ?string $vin = null;

    #[ApiProperty(description: 'Set true to act against a NEW vehicle even though candidates were surfaced. Defaults to false.')]
    public bool $confirmNew = false;

    #[ApiProperty(schema: ['type' => 'string', 'description' => 'The service to remove, as the same snake_case type the task was planned under (e.g. timing_belt, oil_change). Together with label this is the key that identifies which open task to remove.'])]
    #[Assert\Length(max: 100)]
    public string $type;

    #[ApiProperty(description: 'Free-text label of the task to remove, e.g. "courroie de distribution". Must match the planned task\'s label — pass it when the task carries one; omit when it has none.')]
    #[Assert\Length(max: 200)]
    public ?string $label = null;
}
