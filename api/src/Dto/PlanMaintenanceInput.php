<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Maintenance\MaintenanceType;

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
     * @var list<array{ref: string, label?: string, brand?: string, qty?: int}>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string'], 'label' => ['type' => 'string'], 'brand' => ['type' => 'string'], 'qty' => ['type' => 'integer']], 'required' => ['ref']], 'description' => 'Parts/fluids the task needs — the shopping list. Each has a part number "ref" and optional "label", "brand", "qty".'])]
    public array $parts = [];

    /**
     * @var list<array{key: string, value: string, unit?: string, source?: string, url?: string}>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'source' => ['type' => 'string', 'enum' => ['web', 'llm', 'user']], 'url' => ['type' => 'string']], 'required' => ['key', 'value']], 'description' => 'Specs about the vehicle learned for this service — oil grade/capacity, coolant type, a part reference worth keeping, distribution belt-vs-chain. Each {key (snake_case, e.g. oil_type, oil_capacity, coolant_type), value, optional unit, source (web|llm|user, defaults to the plan source), url when web-sourced}. These ARE the vehicle facts — get_vehicle_facts reads them back; there is NO separate add-fact tool. Record what is worth remembering here.'])]
    public array $attributes = [];

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['user', 'llm', 'web'], 'description' => 'Where the plan came from. Defaults to "user".'])]
    public string $source = 'user';

    #[ApiProperty(description: 'Who/what added the plan (optional attribution).')]
    public ?string $addedBy = null;
}
