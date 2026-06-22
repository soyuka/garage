<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class SetMaintenanceScheduleInput
{
    #[ApiProperty(description: 'Target an existing vehicle by its @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…") — the way to resolve an ambiguous_match. When set, make/model are ignored.')]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'Vehicle make, e.g. "TORO". Case-insensitive. Required unless vehicle is given.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Vehicle model, e.g. "246-H". Case-insensitive. Required unless vehicle is given.')]
    public ?string $model = null;

    #[ApiProperty(description: 'Model year, when known.')]
    public ?int $year = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Vehicle segment. Defaults to "car". Use "groundscare" for hour-metered machinery (mowers, tractors).'])]
    public string $vehicleType = 'car';

    #[ApiProperty(description: 'TecDoc kType (carId) of the vehicle, when known.')]
    public ?int $carId = null;

    #[ApiProperty(description: 'Registration plate, when known.')]
    public ?string $plate = null;

    #[ApiProperty(description: 'VIN, when known.')]
    public ?string $vin = null;

    #[ApiProperty(description: 'Set true to write against a NEW vehicle even though candidates were surfaced. Defaults to false.')]
    public bool $confirmNew = false;

    /**
     * @var list<array{type: string, action: string, component?: string, everyUsageValue?: int, everyUsageUnit?: string, everyMonths?: int, firstUsageValue?: int, url?: string}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'Service, snake_case: oil_change, oil_filter, air_filter, fuel_filter, spark_plug, brakes, coolant, tires, inspection… Any descriptive value for the long tail.'],
                'action' => ['type' => 'string', 'enum' => ['change', 'replace', 'clean', 'inspect'], 'description' => 'What the rule does to the component. One type can hold several cadences via different actions: spark_plug INSPECT every 25 h vs REPLACE every 100 h.'],
                'component' => ['type' => 'string', 'description' => 'Sub-part when one type splits, e.g. the air filter\'s "foam element" (cleaned) vs "paper cartridge" (replaced). Part of the dedup key.'],
                'everyUsageValue' => ['type' => 'integer', 'description' => 'Recur every N usage units (the axis that matters for hour-metered machinery, e.g. 50 for "every 50 h"). Pair with everyUsageUnit.'],
                'everyUsageUnit' => ['type' => 'string', 'enum' => ['km', 'mi', 'hours'], 'description' => 'Unit of everyUsageValue. Use "hours" for engine-hour machinery.'],
                'everyMonths' => ['type' => 'integer', 'description' => 'Recur every N months ("or once a year" = 12). When both axes are set the soonest wins (whichever-first).'],
                'firstUsageValue' => ['type' => 'integer', 'description' => 'Break-in override: the FIRST occurrence falls earlier, e.g. 5 for "first oil change at 5 h, then every 50 h". Same unit as everyUsageUnit.'],
                'url' => ['type' => 'string', 'description' => 'Source URL when the cadence came from the web.'],
            ],
            'required' => ['type', 'action'],
        ],
        'description' => 'The recurring service rules. Emit ONE entry per (component, action, cadence): a manufacturer schedule with "oil every 50 h (first at 5 h), oil filter every 200 h, foam pre-filter cleaned every 25 h or yearly, paper cartridge replaced every 100 h or yearly, spark plug inspected every 25 h and replaced every 100 h" is SEVEN entries — never collapse it to one. Each rule needs at least one cadence (everyUsageValue, everyMonths or firstUsageValue).',
    ])]
    public array $items = [];

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['user', 'llm', 'web'], 'description' => 'Where the schedule came from. Defaults to "web" (a manufacturer/owner-manual lookup); "web"/"llm" are advisory, not authoritative.'])]
    public string $source = 'web';

    #[ApiProperty(description: 'Who/what set the schedule (optional attribution).')]
    public ?string $addedBy = null;
}
