<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /garage/vehicles/{id}/facts — a single user-supplied spec the dashboard records
 * straight onto a vehicle's fact store, with no agent round-trip. Always landed as source "user"
 * (unverified): a manually keyed value never shadows an authoritative catalog/SIV fact for the same
 * key (see {@see \App\Maintenance\VehicleFactsProjector}).
 */
final class VehicleFactInput
{
    #[Assert\NotBlank]
    #[ApiProperty(description: 'Fact key, e.g. "oil_capacity", "oil_type", "coolant".')]
    #[Assert\Length(max: 100)]
    public string $key = '';

    #[Assert\NotBlank]
    #[ApiProperty(description: 'The value, e.g. "4.3" or "ACEA A5/B5".')]
    #[Assert\Length(max: 2000)]
    public string $value = '';

    #[ApiProperty(description: 'Optional unit for a quantity, e.g. "L". Omit for a spec string.')]
    #[Assert\Length(max: 100)]
    public ?string $unit = null;
}
