<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class PlanMaintenanceInput
{
    #[ApiProperty(description: 'Target an existing vehicle by its @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…") — the way to resolve an ambiguous_match: the task is planned on that row. When set, make/model are ignored.')]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'Vehicle make, e.g. "MAZDA". Case-insensitive. Required unless vehicle is given.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Vehicle model, e.g. "MAZDA6". Case-insensitive. Required unless vehicle is given.')]
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

    #[ApiProperty(description: 'Set true to plan against a NEW vehicle even though candidates were surfaced. Defaults to false.')]
    public bool $confirmNew = false;

    #[ApiProperty(schema: ['type' => 'string', 'description' => 'The service, as a snake_case label. Common: oil_change, oil_filter, air_filter, fuel_filter, cabin_filter, spark_plug, brakes, coolant, tires, battery, inspection, timing_belt, gearbox_oil. Use any descriptive value for anything else — never collapse to "other". Re-planning the same type on the same vehicle UPDATES the existing open task.'])]
    public string $type;

    #[ApiProperty(description: 'Free-text description, e.g. "courroie de distribution". Distinguishes two "other" tasks on the same vehicle.')]
    public ?string $label = null;

    #[ApiProperty(description: 'Usage reading the work is pegged to, e.g. 250000 for "à 250 000 km". Pair with targetUsageUnit.')]
    public ?int $targetUsageValue = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['km', 'mi', 'h'], 'description' => 'Unit of targetUsageValue: "km", "mi" or engine "h". Pair with targetUsageValue.'])]
    public ?string $targetUsageUnit = null;

    #[ApiProperty(description: 'Date the work should be done by, ISO 8601 (e.g. "2026-09-01"), when time-based.')]
    public ?string $dueDate = null;

    /**
     * @var list<array{ref: string, label?: string, brand?: string, qty?: int, price?: float, supplier?: string, findRoute?: string}>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string'], 'label' => ['type' => 'string'], 'brand' => ['type' => 'string'], 'qty' => ['type' => 'integer'], 'price' => ['type' => 'number', 'description' => 'Unit price in major units (12.50 for €12.50); stored as integer cents (priceCents) like the visit cost.'], 'supplier' => ['type' => 'string', 'description' => 'Where the part is bought, e.g. "trodo.fr".'], 'findRoute' => ['type' => 'string', 'description' => 'Finder result this part was added from, as an in-app hash path without the leading "#" (e.g. "/p/XE304ZE/c/100538/r/c2/<ulid>"). Re-adding the same ref with a new findRoute replaces it; omitting it keeps the stored one.']], 'required' => ['ref']], 'description' => 'Parts/fluids the task needs — the shopping list. Each has a part number "ref" and optional "label", "brand", "qty", "price", "supplier". Re-planning an existing ref updates only the keys supplied.'])]
    public array $parts = [];

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['user', 'llm', 'web'], 'description' => 'Where the plan came from. Defaults to "user".'])]
    public string $source = 'user';

    #[ApiProperty(description: 'Who/what added the plan (optional attribution).')]
    public ?string $addedBy = null;
}
