<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class ListServiceDefaultsInput
{
    #[ApiProperty(description: 'Service type to look up, e.g. "timing_belt", "spark_plug", "brake_fluid", "oil_change", "fuel_filter". Synonyms are accepted ("courroie de distribution", "vidange", "bougies") and canonicalised. Omit to list the whole catalogue.')]
    public ?string $type = null;

    #[ApiProperty(description: 'Vehicle @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…"). When given, the discriminant is read from THAT vehicle\'s facts and the baselines are scoped to its segment (a mower gets the engine-hour cadence, not the car one). Nothing is ever written to the vehicle.')]
    public ?string $vehicle = null;
}
