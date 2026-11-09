<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Maintenance\MaintenanceAction;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /garage/vehicles/{id}/service-schedule — one recurring rule keyed by hand on the
 * dashboard. Landed as source "user" (unverified) by the processor, so it never shadows a catalog/SIV
 * rule for the same (type, action, component) key.
 */
final class ServiceScheduleInput
{
    #[Assert\NotBlank]
    #[ApiProperty(description: 'Service, snake_case: oil_change, oil_filter, air_filter, spark_plug, brakes, coolant, tires, inspection…')]
    #[Assert\Length(max: 100)]
    public string $type = '';

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [MaintenanceAction::class, 'values'])]
    #[ApiProperty(description: 'What the rule does: change | replace | clean | inspect. Lets one type hold several cadences.')]
    public string $action = '';

    #[ApiProperty(description: 'Sub-part when one type splits, e.g. air filter "foam element" vs "paper cartridge". Part of the dedup key.')]
    #[Assert\Length(max: 200)]
    public ?string $component = null;

    #[ApiProperty(description: 'Recur every N usage units (e.g. 50 for "every 50 h"). Pair with everyUsageUnit.')]
    public ?int $everyUsageValue = null;

    #[Assert\Choice(choices: ['km', 'mi', 'hours'])]
    #[ApiProperty(description: 'Unit of everyUsageValue: km | mi | hours.')]
    public ?string $everyUsageUnit = null;

    #[ApiProperty(description: 'Recur every N months ("or once a year" = 12). When both axes apply, soonest wins.')]
    public ?int $everyMonths = null;

    #[ApiProperty(description: 'Break-in override: the first occurrence falls earlier (e.g. 5 for "first oil change at 5 h").')]
    public ?int $firstUsageValue = null;

    #[ApiProperty(description: 'Source URL when the cadence came from a manual / web lookup.')]
    #[Assert\Length(max: 2000)]
    public ?string $url = null;
}
