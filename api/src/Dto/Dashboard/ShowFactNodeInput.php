<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class ShowFactNodeInput
{
    #[ApiProperty(description: 'A stable identifier for the line, e.g. "oil_capacity". Used as the row key; when it matches a service type it also lights that body region on the car.')]
    public string $key = '';

    #[ApiProperty(description: 'The French label shown on the line, e.g. "Huile moteur".')]
    public string $label = '';

    #[ApiProperty(description: 'The value, ALREADY formatted for display — the dashboard renders it verbatim. e.g. "4,3 l", "5W-30", "Diesel".')]
    public string $value = '';

    #[ApiProperty(description: 'True when the value comes from a catalog or official source (it wears a ✓); false when inferred.')]
    public bool $verified = false;

    #[ApiProperty(description: 'Optional secondary spec line under the value, e.g. "ACEA E3|E4|E5".')]
    public ?string $info = null;
}
