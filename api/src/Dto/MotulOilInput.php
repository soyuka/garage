<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class MotulOilInput
{
    #[ApiProperty(description: 'PREFERRED: target an existing garage vehicle by its @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…"), typically the selected one. The make/model, year and engine are then read from the stored row and its plate, so the variant is pinned without you supplying them. When set, search/year/engine below are optional overrides.')]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'Free-text vehicle to look up, e.g. "Mazda 6" or "Mazda 6 1.8". Required only when no vehicle @id is given; make + model is enough.')]
    public ?string $search = null;

    #[ApiProperty(description: 'Model year to disambiguate the variant (e.g. 2004). Inferred from the vehicle @id when given; pass it only to override.')]
    public ?int $year = null;

    #[ApiProperty(description: 'Engine designation to disambiguate, e.g. "1.8", "2.0 CiTD". Inferred from the vehicle @id (its plate) when given; pass it only to override.')]
    public ?string $engine = null;
}
