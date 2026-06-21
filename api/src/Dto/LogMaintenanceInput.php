<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Maintenance\MaintenanceType;
use App\Maintenance\UsageUnit;

final class LogMaintenanceInput
{
    #[ApiProperty(description: 'Target an existing vehicle by its @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…") — the way to resolve an ambiguous_match: the record is appended to that row. When set, make/model are ignored.')]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'Vehicle make, e.g. "TORO", "IVECO". Case-insensitive. Required unless vehicle is given.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Vehicle model, e.g. "246-H", "DAILY". Case-insensitive. Required unless vehicle is given.')]
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

    #[ApiProperty(description: 'Set true to log against a NEW vehicle even though candidates were surfaced — i.e. you have judged it is genuinely a different vehicle. Defaults to false.')]
    public bool $confirmNew = false;

    /**
     * @var list<array{type: string, label?: string, parts?: list<array{ref: string, brand?: string, label?: string}>}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'minItems' => 1,
        'items' => [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'Service type, snake_case: oil_change, oil_filter, air_filter, fuel_filter, cabin_filter, spark_plug, brakes, coolant, tires, battery, inspection, timing_belt, gearbox_oil. Any descriptive value for the long tail — never collapse to "other".'],
                'label' => ['type' => 'string', 'description' => 'Optional per-item label, e.g. "blade sharpening" for an "other" item.'],
                'parts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string'], 'brand' => ['type' => 'string'], 'label' => ['type' => 'string']], 'required' => ['ref']], 'description' => 'Parts/fluids this item consumed, each a part number "ref" with optional brand/label.'],
            ],
            'required' => ['type'],
        ],
        'description' => 'The line-items of ONE service visit — a vidange that changed oil AND the oil filter is a SINGLE log_maintenance call with TWO items, not two calls. Each item is one service type with the parts it used.',
    ])]
    public array $items = [];

    #[ApiProperty(description: 'Optional label for the whole visit, e.g. "révision annuelle".')]
    public ?string $label = null;

    #[ApiProperty(description: 'Date the work was performed, ISO 8601 (e.g. "2026-06-15").')]
    public string $performedAt;

    #[ApiProperty(description: 'Odometer / hour-meter reading at the time of service, when known.')]
    public ?int $usageValue = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['km', 'mi', 'hours'], 'description' => 'Unit of usageValue. Use "hours" for groundscare/engine-hour machinery (e.g. TORO mowers).'])]
    public ?string $usageUnit = null;

    #[ApiProperty(description: 'Free-text notes about the service.')]
    public ?string $notes = null;

    #[ApiProperty(description: 'Cost of the work as a decimal string, e.g. "89.90". Pair with currency.')]
    public ?string $cost = null;

    #[ApiProperty(description: 'ISO 4217 currency code for cost, e.g. "EUR".')]
    public ?string $currency = null;

    #[ApiProperty(description: 'Who did the work: "self" or the name of the garage that performed it.')]
    public ?string $performedBy = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['user', 'llm', 'web'], 'description' => 'Where the record came from. Defaults to "user" (the owner did/knows it — ground truth).'])]
    public string $source = 'user';

    #[ApiProperty(description: 'Who/what added the record (optional attribution).')]
    public ?string $addedBy = null;
}
