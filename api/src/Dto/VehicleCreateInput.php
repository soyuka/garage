<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The (optional) body of POST /garage/vehicles. The dashboard's "+ Ajouter" sends an empty object;
 * the processor seeds placeholder identity the user then corrects on the editable card. Any field
 * supplied here is just a head start — none is required, since the card owns the real values.
 */
final class VehicleCreateInput
{
    #[ApiProperty(description: 'Optional make to pre-fill; defaults to a placeholder the user edits.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Optional model to pre-fill.')]
    public ?string $model = null;

    #[ApiProperty(description: 'Vehicle segment: "car", "motorcycle", "cv" or "groundscare". Defaults to "car".')]
    public ?string $vehicleType = null;
}
