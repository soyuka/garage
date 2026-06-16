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

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['oil_change', 'oil_filter', 'air_filter', 'fuel_filter', 'cabin_filter', 'spark_plug', 'tires', 'brakes', 'coolant', 'battery', 'inspection', 'repair', 'other'], 'description' => 'What was done. Use "other" with a free-text label for anything not listed.'])]
    public string $type;

    #[ApiProperty(description: 'Free-text description of the work. Required in spirit when type is "other" (e.g. "blade sharpening"); optional otherwise.')]
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

    /**
     * @var list<array{ref: string, label?: string}>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string'], 'label' => ['type' => 'string']], 'required' => ['ref']], 'description' => 'Parts consumed, each with a part number "ref" and optional "label".'])]
    public array $partsUsed = [];

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['user', 'llm', 'web'], 'description' => 'Where the record came from. Defaults to "user" (the owner did/knows it — ground truth).'])]
    public string $source = 'user';

    #[ApiProperty(description: 'Who/what added the record (optional attribution).')]
    public ?string $addedBy = null;
}
